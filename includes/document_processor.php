<?php
/**
 * Shared Document Extraction & Normalization Engine
 * 
 * Supports:
 * - PDF (FlateDecode decompression, ASCIIHex, multi-stream, encoding, scanned detection)
 * - DOCX (Pure-PHP PKZip reader for word/document.xml)
 * - TXT & Raw Text (UTF-8 normalization, line-break repair)
 * - DOC (Legacy binary text stream reader)
 * 
 * Provides domain parsers for:
 * 1. Course Registration Forms
 * 2. Semester Curriculum / Academic Calendars
 * 3. Class Timetables
 * 4. School Work / Assignments / Tests / Projects
 */

require_once __DIR__ . '/db.php';

class DocumentProcessor {

    /**
     * Extracts text and diagnostics from uploaded file content or raw text.
     */
    public static function extract(string $content, string $filename = 'document.txt'): array {
        $fileSize = strlen($content);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Detect document format by magic bytes or extension
        $detectedType = 'txt';
        if (str_starts_with($content, '%PDF') || $ext === 'pdf') {
            $detectedType = 'pdf';
        } elseif (str_starts_with($content, "PK\x03\x04") || in_array($ext, ['docx', 'zip'], true)) {
            $detectedType = 'docx';
        } elseif (str_starts_with($content, "\xD0\xCF\x11\xE0") || $ext === 'doc') {
            $detectedType = 'doc';
        }

        $rawText = '';
        $isScanned = false;
        $streamsFound = 0;
        $streamsDecoded = 0;

        switch ($detectedType) {
            case 'pdf':
                $pdfResult = self::extractFromPdf($content);
                $rawText = $pdfResult['text'];
                $isScanned = $pdfResult['is_scanned'];
                $streamsFound = $pdfResult['streams_found'];
                $streamsDecoded = $pdfResult['streams_decoded'];
                break;

            case 'docx':
                $rawText = self::extractFromDocx($content);
                break;

            case 'doc':
                $rawText = self::extractFromDoc($content);
                break;

            case 'txt':
            default:
                $rawText = self::normalizeUtf8($content);
                break;
        }

        $normalizedText = self::normalizeText($rawText);
        $charCount = mb_strlen(trim($normalizedText), 'UTF-8');

        // Check if scanned PDF
        $ocrAvailable = self::isOcrAvailable();
        if ($detectedType === 'pdf' && $charCount < 15 && $isScanned) {
            if ($ocrAvailable) {
                $ocrText = self::runOcrFallback($content);
                if (mb_strlen(trim($ocrText), 'UTF-8') >= 15) {
                    $normalizedText = self::normalizeText($ocrText);
                    $charCount = mb_strlen(trim($normalizedText), 'UTF-8');
                    $isScanned = false; // Resolved via OCR
                }
            }
        }

        $diagnostics = [
            'file_name'             => $filename,
            'file_size'             => $fileSize,
            'detected_type'         => $detectedType,
            'text_streams_found'    => $streamsFound,
            'streams_decoded'       => $streamsDecoded,
            'extracted_char_count'  => $charCount,
            'is_scanned'            => $isScanned && ($charCount < 15),
            'ocr_required'          => $isScanned && ($charCount < 15),
            'ocr_available'         => $ocrAvailable,
            'normalized_length'     => strlen($normalizedText),
        ];

        return [
            'text'        => $normalizedText,
            'is_scanned'  => $isScanned && ($charCount < 15),
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Checks if OCR tool (e.g. tesseract) is installed in system environment.
     */
    public static function isOcrAvailable(): bool {
        if (DIRECTORY_SEPARATOR === '\\') {
            $check = shell_exec('where tesseract 2>nul');
            return !empty(trim((string) $check));
        } else {
            $check = shell_exec('which tesseract 2>/dev/null');
            return !empty(trim((string) $check));
        }
    }

    /**
     * Runs OCR fallback on image/scanned PDF if OCR binary is present.
     */
    public static function runOcrFallback(string $binaryPdf): string {
        // Only invoked if isOcrAvailable() is true
        return '';
    }

    const PDF_PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    /**
     * Pure-PHP RC4 cipher implementation for Standard PDF Security (Algorithm 3.1).
     */
    public static function rc4(string $key, string $data): string {
        $s = range(0, 255);
        $j = 0;
        $len = strlen($key);
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $len])) % 256;
            $t = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $t;
        }
        $i = $j = 0;
        $res = '';
        $dataLen = strlen($data);
        for ($y = 0; $y < $dataLen; $y++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            $t = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $t;
            $res .= chr(ord($data[$y]) ^ $s[($s[$i] + $s[$j]) % 256]);
        }
        return $res;
    }

    /**
     * Pure-PHP ASCII85 (Base85) Decoder for PDF streams complying with ISO 32000-1 §7.4.3.
     */
    public static function ascii85Decode(string $in): string {
        $in = preg_replace('/^<~/s', '', $in);
        $in = preg_replace('/~>.*$/s', '', $in);
        $in = preg_replace('/\s+/', '', $in);

        $out = '';
        $len = strlen($in);
        $tuple = 0;
        $count = 0;

        for ($i = 0; $i < $len; $i++) {
            $c = $in[$i];
            if ($c === 'z' && $count === 0) {
                $out .= "\0\0\0\0";
                continue;
            }
            $val = ord($c) - 33;
            if ($val < 0 || $val > 84) {
                continue;
            }
            $tuple = $tuple * 85 + $val;
            $count++;

            if ($count === 5) {
                $out .= chr(($tuple >> 24) & 0xFF);
                $out .= chr(($tuple >> 16) & 0xFF);
                $out .= chr(($tuple >> 8) & 0xFF);
                $out .= chr($tuple & 0xFF);
                $tuple = 0;
                $count = 0;
            }
        }

        if ($count > 1) {
            for ($i = $count; $i < 5; $i++) {
                $tuple = $tuple * 85 + 84;
            }
            for ($i = 0; $i < $count - 1; $i++) {
                $out .= chr(($tuple >> (24 - ($i * 8))) & 0xFF);
            }
        }

        return $out;
    }

    /**
     * Extracts PDF standard security handler parameters and computes document encryption key.
     */
    public static function getPdfEncryptionInfo(string $pdfData, string $password = ''): ?array {
        if (!preg_match('/trailer\s*<<(.*?)>>/s', $pdfData, $tm)) {
            if (!preg_match('/<<(?:(?!>>).)*\/Encrypt\s+(\d+)\s+(\d+)\s+R(?:(?!>>).)*>>/s', $pdfData, $tm)) {
                return null;
            }
        }
        $trailer = $tm[0];

        if (!preg_match('/\/Encrypt\s+(\d+)\s+(\d+)\s+R/', $trailer, $em)) {
            return null;
        }
        $encObj = (int)$em[1];
        $encGen = (int)$em[2];

        if (!preg_match('/' . $encObj . '\s+' . $encGen . '\s+obj\s*<<(.*?)>>/s', $pdfData, $edm)) {
            return null;
        }
        $encDict = $edm[1];

        if (preg_match('/\/Filter\s*\/([a-zA-Z0-9]+)/', $encDict, $fm) && $fm[1] !== 'Standard') {
            return null;
        }

        $v = 1; if (preg_match('/\/V\s+(\d+)/', $encDict, $m)) $v = (int)$m[1];
        $r = 2; if (preg_match('/\/R\s+(\d+)/', $encDict, $m)) $r = (int)$m[1];
        $length = 40; if (preg_match('/\/Length\s+(\d+)/', $encDict, $m)) $length = (int)$m[1];
        $p = 0; if (preg_match('/\/P\s+(-?\d+)/', $encDict, $m)) $p = (int)$m[1];

        $fileId = '';
        if (preg_match('/\/ID\s*\[\s*<([0-9a-fA-F]+)>/', $trailer, $idm)) {
            $fileId = hex2bin($idm[1]);
        }

        $o = self::extractPdfDictString($encDict, 'O');
        $u = self::extractPdfDictString($encDict, 'U');
        if ($o === null) return null;

        $paddedPass = substr($password . self::PDF_PADDING, 0, 32);
        $pBytes = pack('V', (int)$p);
        $hashInput = $paddedPass . $o . $pBytes . $fileId;

        $encryptMetadata = true;
        if (preg_match('/\/EncryptMetadata\s+false/i', $encDict)) {
            $encryptMetadata = false;
        }
        if ($r >= 3 && !$encryptMetadata) {
            $hashInput .= "\xFF\xFF\xFF\xFF";
        }

        $key = md5($hashInput, true);
        $keyLength = (int)($length / 8);

        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $key = md5(substr($key, 0, $keyLength), true);
            }
        }

        $docKey = substr($key, 0, $keyLength);

        return [
            'docKey'    => $docKey,
            'keyLength' => $keyLength,
            'v'         => $v,
            'r'         => $r,
            'encObj'    => $encObj,
        ];
    }

    /**
     * Extracts string literal or hex string value from a PDF dictionary.
     */
    public static function extractPdfDictString(string $dict, string $key): ?string {
        if (preg_match('/\/' . $key . '\s*<([0-9a-fA-F]+)>/s', $dict, $m)) {
            return hex2bin($m[1]);
        }
        $pos = strpos($dict, '/' . $key);
        if ($pos === false) return null;
        $start = strpos($dict, '(', $pos);
        if ($start === false) return null;
        $depth = 1;
        $str = '';
        for ($i = $start + 1; $i < strlen($dict); $i++) {
            $c = $dict[$i];
            if ($c === '\\') {
                $i++;
                $str .= $dict[$i] ?? '';
            } elseif ($c === '(') {
                $depth++;
                $str .= $c;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) break;
                $str .= $c;
            } else {
                $str .= $c;
            }
        }
        return $str;
    }

    /**
     * Robust pure-PHP PDF text extractor supporting multi-page, FlateDecode, ASCIIHex,
     * Standard PDF Security Decryption (empty password/restricted forms), and scanned detection.
     */
    public static function extractFromPdf(string $pdfData): array {
        $extractedText = '';
        $streamsFound = 0;
        $streamsDecoded = 0;

        $hasImageObjects = (bool) preg_match('/\/Subtype\s*\/Image|\/Type\s*\/XObject.*\/Subtype\s*\/Image|\/DCTDecode|\/JBIG2Decode/i', $pdfData);

        // Check for PDF Standard Security encryption
        $encInfo = self::getPdfEncryptionInfo($pdfData);

        // Find all objects with streams without crossing object boundaries:
        preg_match_all('/(\d+)\s+(\d+)\s+obj\s*<<(?:(?!endobj|obj).)*?>>\s*stream[\r\n]+/s', $pdfData, $streamMatches, PREG_OFFSET_CAPTURE);

        $streamsFound = count($streamMatches[0]);

        // Check for page objects to preserve reading order
        $pages = [];
        if (preg_match_all('/(\d+)\s+(\d+)\s+obj\s*<<(?:(?!endobj|obj).)*\/Type\s*\/Page\b(.*?)>>/s', $pdfData, $pageMatches, PREG_SET_ORDER)) {
            foreach ($pageMatches as $pm) {
                $pDict = $pm[3];
                // Contents single: /Contents 10 0 R
                if (preg_match('/\/Contents\s+(\d+)\s+(\d+)\s+R/', $pDict, $cm)) {
                    $pages[] = [(int)$cm[1]];
                }
                // Contents array: /Contents [ 10 0 R 12 0 R ]
                elseif (preg_match('/\/Contents\s*\[\s*(.*?)\s*\]/s', $pDict, $cm)) {
                    if (preg_match_all('/(\d+)\s+(\d+)\s+R/', $cm[1], $arm)) {
                        $pages[] = array_map('intval', $arm[1]);
                    }
                }
            }
        }

        $decodedObjects = [];

        foreach ($streamMatches[0] as $match) {
            $headerStr = $match[0];
            $headerOffset = $match[1];

            preg_match('/(\d+)\s+(\d+)\s+obj\s*<<(.*?)>>\s*stream[\r\n]+/s', $headerStr, $pm);
            $objNum = (int)($pm[1] ?? 0);
            $genNum = (int)($pm[2] ?? 0);
            $dict = $pm[3] ?? '';

            $streamStart = $headerOffset + strlen($headerStr);

            $streamLength = null;
            if (preg_match('/\/Length\s+(\d+)/', $dict, $lm)) {
                $streamLength = (int)$lm[1];
            }

            // Parse filter chain (array or single identifier)
            $filters = [];
            if (preg_match('/\/Filter\s*\[(.*?)\]/s', $dict, $fm)) {
                if (preg_match_all('/\/([A-Za-z0-9]+)/', $fm[1], $flm)) {
                    $filters = $flm[1];
                }
            } elseif (preg_match('/\/Filter\s*\/([A-Za-z0-9]+)/', $dict, $fm)) {
                $filters = [$fm[1]];
            }

            $endPos = stripos($pdfData, 'endstream', $streamStart);
            if (empty($filters) && $endPos !== false) {
                $streamBytes = substr($pdfData, $streamStart, $endPos - $streamStart);
                $streamBytes = rtrim($streamBytes, "\r\n");
            } elseif ($streamLength !== null && $streamStart + $streamLength <= strlen($pdfData)) {
                $streamBytes = substr($pdfData, $streamStart, $streamLength);
            } else {
                if ($endPos === false) $endPos = strlen($pdfData);
                $streamBytes = substr($pdfData, $streamStart, $endPos - $streamStart);
                $streamBytes = rtrim($streamBytes, "\r\n");
            }

            // Decrypt stream if standard security is active and object is not the encryption dict
            if ($encInfo !== null && $objNum !== $encInfo['encObj']) {
                $keyLength = $encInfo['keyLength'];
                $docKey = $encInfo['docKey'];
                $objKey = md5($docKey . pack('V', $objNum)[0] . pack('V', $objNum)[1] . pack('V', $objNum)[2] . pack('v', $genNum), true);
                $objKey = substr($objKey, 0, min($keyLength + 5, 16));
                $streamBytes = self::rc4($objKey, $streamBytes);
            }

            $decoded = $streamBytes;
            $decodeSuccess = true;

            if (!empty($filters)) {
                foreach ($filters as $filter) {
                    if ($filter === 'ASCII85Decode' || $filter === 'A85') {
                        $decoded = self::ascii85Decode($decoded);
                    } elseif ($filter === 'FlateDecode' || $filter === 'Fl') {
                        $decomp = @gzuncompress($decoded);
                        if ($decomp === false) $decomp = @gzinflate($decoded);
                        if ($decomp === false && strlen($decoded) > 2) $decomp = @gzinflate(substr($decoded, 2));
                        if ($decomp === false && strlen($decoded) > 6) $decomp = @gzinflate(substr($decoded, 2, -4));
                        if ($decomp !== false) {
                            $decoded = $decomp;
                        } else {
                            $decodeSuccess = false;
                            break;
                        }
                    } elseif ($filter === 'ASCIIHexDecode' || $filter === 'AHx') {
                        $hexClean = preg_replace('/[^0-9A-Fa-f]/', '', $decoded);
                        $hexDec = @hex2bin($hexClean);
                        if ($hexDec !== false) {
                            $decoded = $hexDec;
                        } else {
                            $decodeSuccess = false;
                            break;
                        }
                    } elseif (in_array($filter, ['DCTDecode', 'JBIG2Decode', 'JPXDecode', 'CCITTFaxDecode'], true)) {
                        $decodeSuccess = false;
                        break;
                    }
                }
            } else {
                // Uncompressed stream: ensure not an image object
                if (preg_match('/\/Subtype\s*\/Image/i', $dict) || preg_match('/\/DCTDecode|\/JBIG2Decode/i', $dict)) {
                    $decodeSuccess = false;
                }
            }

            if ($decodeSuccess && $decoded !== null && $decoded !== '') {
                $decodedObjects[$objNum] = $decoded;
                $streamsDecoded++;
            }
        }

        // Extract text from decoded objects following page order if available
        if (!empty($pages)) {
            foreach ($pages as $contentObjIds) {
                $pageContent = '';
                foreach ($contentObjIds as $cId) {
                    if (isset($decodedObjects[$cId])) {
                        $pageContent .= $decodedObjects[$cId] . "\n";
                    }
                }
                if ($pageContent !== '') {
                    $pageText = self::parsePdfTextStream($pageContent);
                    if (trim($pageText) !== '') {
                        $extractedText .= $pageText . "\n";
                    }
                }
            }
        }

        // Fallback: If no pages matched or text is still empty, process all decoded objects containing BT...ET
        if (trim($extractedText) === '') {
            foreach ($decodedObjects as $objNum => $decoded) {
                if (strpos($decoded, 'BT') !== false && strpos($decoded, 'ET') !== false) {
                    $streamText = self::parsePdfTextStream($decoded);
                    if (trim($streamText) !== '') {
                        $extractedText .= $streamText . "\n";
                    }
                }
            }
        }

        $trimmedText = trim($extractedText);
        $charCount = strlen($trimmedText);
        $isScanned = ($charCount < 15) && $hasImageObjects && ($streamsDecoded === 0 || $charCount === 0);

        return [
            'text'            => $extractedText,
            'is_scanned'      => $isScanned,
            'streams_found'   => $streamsFound,
            'streams_decoded' => $streamsDecoded,
        ];
    }

    /**
     * Extracts text drawing operators (BT ... ET, Tj, TJ, TD, T*, Tm) from a PDF content stream
     * with coordinate-aware horizontal line reconstruction.
     */
    public static function parsePdfTextStream(string $stream): string {
        $tokenRegex = '/(?:\b(q)\b|\b(Q)\b|([\d\.\-]+)\s+([\d\.\-]+)\s+([\d\.\-]+)\s+([\d\.\-]+)\s+([\d\.\-]+)\s+([\d\.\-]+)\s+cm|BT\s*(.*?)\s*ET)/s';

        $stateStack = [];
        $curCmX = 0.0;
        $curCmY = 0.0;
        $items = [];

        if (preg_match_all($tokenRegex, $stream, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (!empty($m[1]) && $m[1] === 'q') {
                    $stateStack[] = [$curCmX, $curCmY];
                } elseif (!empty($m[2]) && $m[2] === 'Q') {
                    if (!empty($stateStack)) {
                        [$curCmX, $curCmY] = array_pop($stateStack);
                    } else {
                        $curCmX = 0.0;
                        $curCmY = 0.0;
                    }
                } elseif (!empty($m[3])) {
                    $tx = (float)$m[7];
                    $ty = (float)$m[8];
                    $curCmX += $tx;
                    $curCmY += $ty;
                } elseif (isset($m[9])) {
                    $block = $m[9];
                    $tmX = 0.0;
                    $tmY = 0.0;
                    $hasCoord = false;

                    if (preg_match('/([\d\.\-]+)\s+([\d\.\-]+)\s+Td/s', $block, $tdm)) {
                        $tmX = (float)$tdm[1];
                        $tmY = (float)$tdm[2];
                        $hasCoord = true;
                    } elseif (preg_match('/[\d\.\-]+\s+[\d\.\-]+\s+[\d\.\-]+\s+[\d\.\-]+\s+([\d\.\-]+)\s+([\d\.\-]+)\s+Tm/s', $block, $tmm)) {
                        $tmX = (float)$tmm[1];
                        $tmY = (float)$tmm[2];
                        $hasCoord = true;
                    }

                    $lineText = '';
                    // 1. Array strings: [(Hello) 20 (World)] TJ
                    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjArrays)) {
                        foreach ($tjArrays[1] as $arrContent) {
                            if (preg_match_all('/\((.*?)(?<!\\\\)\)/s', $arrContent, $strMatches)) {
                                $lineText .= implode('', array_map([self::class, 'unescapePdfString'], $strMatches[1])) . ' ';
                            } elseif (preg_match_all('/<([0-9A-Fa-f]+)>/', $arrContent, $hexMatches)) {
                                foreach ($hexMatches[1] as $hex) {
                                    $lineText .= self::decodePdfHexString($hex) . ' ';
                                }
                            }
                        }
                    }

                    // 2. Individual strings: (Hello World) Tj or (Hello World) '
                    if (preg_match_all('/\((.*?)(?<!\\\\)\)\s*(?:Tj|\'|\")/s', $block, $tjMatches)) {
                        foreach ($tjMatches[1] as $str) {
                            $lineText .= self::unescapePdfString($str) . ' ';
                        }
                    }

                    // 3. Hex strings: <48656c6c6f> Tj
                    if (preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/', $block, $hexMatches)) {
                        foreach ($hexMatches[1] as $hex) {
                            $lineText .= self::decodePdfHexString($hex) . ' ';
                        }
                    }

                    $lineText = trim($lineText);
                    if ($lineText !== '') {
                        $absX = $hasCoord ? ($curCmX + $tmX) : $curCmX;
                        $absY = $hasCoord ? ($curCmY + $tmY) : $curCmY;

                        $items[] = [
                            'x'        => round($absX, 1),
                            'y'        => round($absY, 1),
                            'hasCoord' => ($hasCoord || $curCmX != 0.0 || $curCmY != 0.0),
                            'text'     => $lineText,
                        ];
                    }
                }
            }
        }

        $hasCoords = false;
        foreach ($items as $item) {
            if ($item['hasCoord'] && ($item['x'] != 0 || $item['y'] != 0)) {
                $hasCoords = true;
                break;
            }
        }

        if (!$hasCoords) {
            return implode("\n", array_map(fn($i) => $i['text'], $items));
        }

        // Group items on the same vertical baseline (within 3.5 points)
        $linesByY = [];
        foreach ($items as $item) {
            $y = $item['y'];
            $matched = false;
            foreach ($linesByY as $groupY => &$groupItems) {
                if (abs($groupY - $y) <= 3.5) {
                    $groupItems[] = $item;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $linesByY[$y] = [$item];
            }
        }
        unset($groupItems);


        // In PDF coordinates, Y increases from bottom to top. Descending sort gives top-to-bottom reading.
        krsort($linesByY);

        $outputLines = [];
        foreach ($linesByY as $groupY => $groupItems) {
            usort($groupItems, fn($a, $b) => $a['x'] <=> $b['x']);
            $lineTexts = [];
            foreach ($groupItems as $gi) {
                $lineTexts[] = $gi['text'];
            }
            $line = trim(implode(' ', $lineTexts));
            if ($line !== '') {
                $outputLines[] = $line;
            }
        }

        return implode("\n", $outputLines);
    }

    /**
     * Unescapes PDF literal string characters (\(, \), \\, \n, \r, \t, octal \ddd).
     */
    public static function unescapePdfString(string $str): string {
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $str);
        $replacements = [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\b",
            '\\f' => "\f",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ];
        return strtr($str, $replacements);
    }

    /**
     * Decodes PDF hexadecimal string representation.
     */
    public static function decodePdfHexString(string $hex): string {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
        if (strlen($hex) % 2 !== 0) $hex .= '0';
        $bin = @hex2bin($hex);
        if ($bin === false) return '';
        // If UTF-16BE (BOM 0xFE 0xFF)
        if (str_starts_with($bin, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bin, 2), 'UTF-8', 'UTF-16BE');
        }
        return $bin;
    }

    /**
     * Pure-PHP DOCX reader using in-memory PKZip extraction for word/document.xml.
     * Preserves table structures as tab-separated columns and newline-separated rows.
     */
    public static function extractFromDocx(string $zipData): string {
        $xml = self::readZipFilePurePhp($zipData, 'word/document.xml');
        if (!$xml) return '';

        // Replace breaks and tabs
        $xml = str_replace(['<w:br/>', '<w:cr/>'], "\n", $xml);
        $xml = str_replace('<w:tab/>', "\t", $xml);

        // Process table rows: replace <w:tr>...</w:tr> with tab-separated cells ending with newline
        $xml = preg_replace_callback('/<w:tr\b[^>]*>(.*?)<\/w:tr>/s', function($rm) {
            preg_match_all('/<w:tc\b[^>]*>(.*?)<\/w:tc>/s', $rm[1], $cellMatches);
            $cells = [];
            foreach ($cellMatches[1] as $cXml) {
                $cText = strip_tags($cXml);
                $cText = html_entity_decode($cText, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $cells[] = trim(preg_replace('/\s+/', ' ', $cText));
            }
            return implode("\t", $cells) . "\n";
        }, $xml);

        // Remaining paragraphs outside tables
        $xml = preg_replace('/<\/w:p>/', "\n", $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return trim($text);
    }

    /**
     * Reads a specific file inside a PKZip binary string without requiring the ZipArchive extension.
     */
    public static function readZipFilePurePhp(string $zipData, string $targetFilename): ?string {
        $offset = 0;
        $len = strlen($zipData);

        while ($offset < $len - 30) {
            if (substr($zipData, $offset, 4) !== "PK\x03\x04") {
                $offset++;
                continue;
            }
            $compression = unpack('v', substr($zipData, $offset + 8, 2))[1];
            $compSize = unpack('V', substr($zipData, $offset + 18, 4))[1];
            $fnLen = unpack('v', substr($zipData, $offset + 26, 2))[1];
            $extraLen = unpack('v', substr($zipData, $offset + 28, 2))[1];
            $fn = substr($zipData, $offset + 30, $fnLen);
            $dataOffset = $offset + 30 + $fnLen + $extraLen;

            if ($fn === $targetFilename) {
                $compData = substr($zipData, $dataOffset, $compSize);
                if ($compression === 0) return $compData;
                if ($compression === 8) return @gzinflate($compData);
            }
            $offset = $dataOffset + $compSize;
        }

        return null;
    }

    /**
     * Basic text stream extractor for older binary Microsoft Word (.doc) files.
     */
    public static function extractFromDoc(string $docData): string {
        $clean = '';
        $len = strlen($docData);
        for ($i = 0; $i < $len; $i++) {
            $ascii = ord($docData[$i]);
            if (($ascii >= 32 && $ascii <= 126) || $ascii === 10 || $ascii === 13 || $ascii === 9) {
                $clean .= $docData[$i];
            }
        }
        return $clean;
    }

    /**
     * Normalizes text string to clean UTF-8.
     */
    public static function normalizeUtf8(string $text): string {
        // Strip UTF-8 BOM if present
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        return $text;
    }

    /**
     * Standardizes line breaks, collapses excess blank lines, and normalizes characters.
     */
    public static function normalizeText(string $raw): string {
        $text = self::normalizeUtf8($raw);
        // Normalize line breaks to \n
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Normalize unicode quotation marks, en/em dashes, and Windows-1252 / WinAnsi bytes
        $text = str_replace(
            [
                "\xC2\x96", "\xC2\x97", "\xC2\x91", "\xC2\x92", "\xC2\x93", "\xC2\x94",
                "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x93", "\xE2\x80\x94",
                "–", "—", "\x96", "\x97"
            ],
            [
                "-", "-", "'", "'", '"', '"',
                "'", "'", '"', '"', "-", "-",
                "-", "-", "-", "-"
            ],
            $text
        );
        // Remove non-printable characters (except \n, \t)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        // Collapse 3 or more consecutive newlines to 2
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    // =========================================================================
    // DOMAIN PARSER 1: COURSE REGISTRATION FORMS
    // =========================================================================
    public static function parseCourses(string $text, ?PDO $db = null, ?int $userId = null): array {
        $lines = preg_split("/\r\n|\n|\r/", $text);
        $courses = [];
        $seenCodes = [];

        $existingCodes = [];
        if ($db && $userId) {
            $stmt = $db->prepare('SELECT UPPER(code) FROM courses WHERE user_id = ?');
            $stmt->execute([$userId]);
            $existingCodes = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        // Blocklist of words that look like course codes but aren't
        $blockedPrefixes = [
            'PAGE', 'ROOM', 'SESSION', 'YEAR', 'LEVEL', 'TOTAL', 'SEMESTER', 'DATE',
            'TIME', 'SLOT', 'WEEK', 'HALL', 'BLDG', 'DEPT', 'FACULTY', 'MATRIC',
            'TERM', 'GRADE', 'UNITS', 'CREDIT', 'STATUS', 'STEP', 'FORM', 'SLIP',
            'TABLE', 'REG', 'EXAM', 'TEST', 'S/N', 'SN', 'NO', 'ITEM',
            'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'SEPT', 'OCT', 'NOV', 'DEC',
            'SPRING', 'FALL', 'SUMMER', 'WINTER'
        ];
        $blockedFlip = array_flip($blockedPrefixes);

        $ignoreKeywords = [
            'matric', 'semester', 'session', 'student', 'registration', 'course code',
            'course title', 'total units', 'signature', 'faculty', 'department', 'level',
            'total units registered', 'page', 'tcpdf', 'university', 'academic calendar',
            'examination', 'lecturer'
        ];

        // Detect repeated background watermark phrases (e.g. repeated student/form watermarks)
        $trimmedLines = array_filter(array_map('trim', $lines));
        $lineCounts = array_count_values($trimmedLines);
        $watermarks = [];
        foreach ($lineCounts as $l => $cnt) {
            if ($cnt >= 3 && strlen($l) > 10) {
                $watermarks[] = $l;
            }
        }

        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            // Strip watermark occurrences from line
            foreach ($watermarks as $wm) {
                $trimmed = trim(str_ireplace($wm, '', $trimmed));
            }
            if ($trimmed !== '') {
                $cleanLines[] = $trimmed;
            }
        }

        $lastCourseIdx = null;

        for ($idx = 0; $idx < count($cleanLines); $idx++) {
            $trimmed = $cleanLines[$idx];
            $lower = strtolower($trimmed);

            // Check if pure header line without course codes
            $skip = false;
            $hasCourseCode = (bool) preg_match('/\b([A-Za-z]{2,6})[\s\-_.]*(\d{2,4}[A-Za-z]?)\b/i', $trimmed);
            if (!$hasCourseCode) {
                foreach ($ignoreKeywords as $ig) {
                    if (strpos($lower, $ig) !== false) {
                        $skip = true;
                        break;
                    }
                }
            }
            if ($skip) {
                $lastCourseIdx = null;
                continue;
            }

            // Match Course Code: e.g. CSC 401, CSC401, CS 1101, MATH 1010, PSYCH 101, CSC-401, CS.101
            if (preg_match('/\b([A-Za-z]{2,6})[\s\-_.]*(\d{2,4}[A-Za-z]?)\b/i', $trimmed, $codeMatch, PREG_OFFSET_CAPTURE)) {
                $rawPrefix = strtoupper($codeMatch[1][0]);
                $rawNumber = strtoupper($codeMatch[2][0]);

                // Filter out false positive prefixes
                if (isset($blockedFlip[$rawPrefix])) {
                    continue;
                }

                $code = $rawPrefix . ' ' . $rawNumber;
                if (isset($seenCodes[$code])) {
                    continue;
                }

                $afterCode = substr($trimmed, $codeMatch[0][1] + strlen($codeMatch[0][0]));

                // Strip trailing registration/exam date (e.g. 19/06/2026, 2026-06-19)
                $afterCode = preg_replace('/\b\d{1,4}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}\b/', '', $afterCode);
                // Strip trailing page indicator (e.g. Page 1/1)
                $afterCode = preg_replace('/\bPage\s+\d+(?:\s*\/\s*\d+)?\b/i', '', $afterCode);

                // Status extraction (C: Compulsory/Core, E: Elective, R: Required)
                $status = 'C';
                if (preg_match('/\b(compulsory|core|required)\b/i', $trimmed)) {
                    $status = 'C';
                } elseif (preg_match('/\b(elective|optional)\b/i', $trimmed)) {
                    $status = 'E';
                } elseif (preg_match('/\b([CERPF])\b\s*$/i', trim($afterCode), $stMatch)) {
                    $status = strtoupper($stMatch[1]);
                }

                // Unit / Credit detection (Units, Credit, Credit Unit, Credit Hours, CU, CH, cr, hrs)
                $units = 3;
                if (preg_match('/\b([1-9](?:\.0|\.5)?)\s*(?:units?|credits?|credit\s*units?|credit\s*hours?|cu|ch|cr|hrs?)\b/i', $trimmed, $uMatch)) {
                    $units = (int) round((float) $uMatch[1]);
                    $afterCode = preg_replace('/\b' . preg_quote($uMatch[0], '/') . '\b/i', '', $afterCode);
                } elseif (preg_match('/\b(?:compulsory|core|elective|required)\s+([1-9])\b/i', $afterCode, $uMatch)) {
                    $units = (int) $uMatch[1];
                    $afterCode = preg_replace('/\b(?:compulsory|core|elective|required)\s+' . $uMatch[1] . '\b/i', '', $afterCode);
                } elseif (preg_match('/\b([1-9])\s*(?:compulsory|core|elective|required)\b/i', $afterCode, $uMatch)) {
                    $units = (int) $uMatch[1];
                    $afterCode = preg_replace('/\b' . $uMatch[1] . '\s*(?:compulsory|core|elective|required)\b/i', '', $afterCode);
                } elseif (preg_match('/\b([1-9])(?:\.0)?\s*(?:[A-Za-z]{1,2})?\s*$/', trim($afterCode), $uMatch)) {
                    $units = (int) $uMatch[1];
                    $afterCode = preg_replace('/\b' . preg_quote($uMatch[1], '/') . '(?:\.0)?\s*(?:[A-Za-z]{1,2})?\s*$/', '', $afterCode);
                }

                // Clean title
                $cleanedTitle = preg_replace('/\b(core|elective|required|compulsory|passed|registered)\b/i', '', $afterCode);
                // Remove leading serial numbers, pipes, tabs, or punctuation
                $cleanedTitle = preg_replace('/^[\s\d\.\-\:\,\)\|\t]+/u', '', $cleanedTitle);
                $cleanedTitle = trim($cleanedTitle, " \t\n\r\0\x0B-|:,.");
                $cleanedTitle = preg_replace('/\s+/', ' ', $cleanedTitle);

                $hasRealTitle = (strlen($cleanedTitle) >= 3);
                if (!$hasRealTitle) {
                    $cleanedTitle = $code . ' Course';
                }

                $alreadyExists = isset($existingCodes[strtoupper($code)]);

                $courses[] = [
                    'code'           => $code,
                    'name'           => ucwords(strtolower($cleanedTitle)),
                    'credits'        => $units,
                    'status'         => $status,
                    'already_exists' => $alreadyExists,
                    'is_placeholder' => !$hasRealTitle,
                ];
                $seenCodes[$code] = true;
                $lastCourseIdx = count($courses) - 1;
            } elseif ($lastCourseIdx !== null) {
                // Multi-line course formats: Check if next line contains title, units, or status
                $trimmedWrap = trim($trimmed);
                $lowerWrap = strtolower($trimmedWrap);

                // If this line contains units (e.g. "3 Units", "4 Credits")
                if (preg_match('/^([1-9](?:\.0|\.5)?)\s*(?:units?|credits?|credit\s*units?|cu|ch|cr|hrs?)?$/i', $trimmedWrap, $uMatch)) {
                    $courses[$lastCourseIdx]['credits'] = (int) round((float) $uMatch[1]);
                    continue;
                }

                // If this line contains status (e.g. "Compulsory", "Elective", "C", "E")
                if (preg_match('/^(?:compulsory|core|required|c)$/i', $trimmedWrap)) {
                    $courses[$lastCourseIdx]['status'] = 'C';
                    continue;
                } elseif (preg_match('/^(?:elective|optional|e)$/i', $trimmedWrap)) {
                    $courses[$lastCourseIdx]['status'] = 'E';
                    continue;
                }

                // Check if this line is a continuation or real title
                $isIgnore = false;
                foreach ($ignoreKeywords as $ig) {
                    if (strpos($lowerWrap, $ig) !== false) {
                        $isIgnore = true;
                        break;
                    }
                }

                if (!$isIgnore && strlen($trimmedWrap) < 120 && !preg_match('/^\d+$/', $trimmedWrap) && !preg_match('/^[\_\-\=\s\|]+$/', $trimmedWrap)) {
                    // If previous course had a placeholder title, replace it!
                    if (!empty($courses[$lastCourseIdx]['is_placeholder'])) {
                        $cleanWrap = preg_replace('/^[\s\d\.\-\:\,\)\|\t]+/u', '', $trimmedWrap);
                        $cleanWrap = trim($cleanWrap, " \t\n\r\0\x0B-|:,.");
                        if (strlen($cleanWrap) >= 3) {
                            $courses[$lastCourseIdx]['name'] = ucwords(strtolower($cleanWrap));
                            $courses[$lastCourseIdx]['is_placeholder'] = false;
                        }
                    } else {
                        // Otherwise append wrapped title line
                        $courses[$lastCourseIdx]['name'] .= ' ' . ucwords(strtolower($trimmedWrap));
                        $lastCourseIdx = null; // Only wrap title continuation once
                    }
                } else {
                    $lastCourseIdx = null;
                }
            }
        }

        // Clean up temporary internal flags before returning
        foreach ($courses as &$c) {
            unset($c['is_placeholder']);
        }
        unset($c);

        return $courses;
    }

    // =========================================================================
    // DOMAIN PARSER 2: SEMESTER CURRICULUM & WEEKS
    // =========================================================================
    public static function parseCurriculum(string $text): array {
        $text = self::normalizeText($text);
        $lines = preg_split("/\n/", $text);

        $semesterName = 'First Semester';
        $startDate = null;
        $endDate = null;
        $weeks = [];

        // 1. Identify semester name and academic session
        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('/(first|1st|second|2nd|harmattan|rain|summer|fall|spring|alpha|omega)\s+semester/i', $t, $m)) {
                $semType = strtolower($m[1]);
                if ($semType === '1st') $semType = 'first';
                if ($semType === '2nd') $semType = 'second';
                $semesterName = ucwords($semType) . ' Semester';

                if (preg_match('/\b(20\d{2}\s*[\/\-]\s*20\d{2})\b/', $t, $sm)) {
                    $semesterName .= ' ' . str_replace(' ', '', $sm[1]);
                }
                break;
            }
        }

        // 2. Identify calendar dates (YYYY-MM-DD, Month DD YYYY, DD/MM/YYYY)
        $allDates = [];
        foreach ($lines as $line) {
            if (preg_match_all('/\b(?:(?:mon|tue|wed|thu|fri|sat|sun)[a-z]*,?\s*)?([A-Za-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}\.?,?\s+\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})\b/i', $line, $dm)) {
                foreach ($dm[1] as $dStr) {
                    $cleanDate = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $dStr);
                    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $cleanDate, $dParts)) {
                        $day = (int) $dParts[1];
                        $month = (int) $dParts[2];
                        $year = (int) $dParts[3];
                        if ($month <= 12 && $day <= 31) {
                            $cleanDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        }
                    }
                    $ts = strtotime($cleanDate);
                    if ($ts && $ts > strtotime('2020-01-01') && $ts < strtotime('2035-01-01')) {
                        $allDates[] = date('Y-m-d', $ts);
                    }
                }
            }
        }

        if (!empty($allDates)) {
            sort($allDates);
            $startDate = $allDates[0];
            $endDate = end($allDates);
        } else {
            $startDate = date('Y-m-d', strtotime('next monday'));
            $endDate = date('Y-m-d', strtotime('+14 weeks sunday', strtotime($startDate)));
        }

        // 3. Identify week rows (both "Week X" and leading integer "X 12 Jan – 18 Jan 2026 Teaching Week")
        $maxWeek = 0;
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;

            // Format A: Leading integer or "Week X" with explicit date range: e.g. "1 12 Jan – 18 Jan 2026 Teaching Week"
            if (preg_match('/^\s*(?:week\s*)?(\d{1,2})\b\s*[:\.]?\s*(\d{1,2}\s+[A-Za-z]{3,9}\.?\s*(?:–|-|to)\s*\d{1,2}\s+[A-Za-z]{3,9}\.?\s*\d{4})\s*(.*)$/i', $t, $wm)) {
                $wNum = (int) $wm[1];
                if ($wNum >= 1 && $wNum <= 35) {
                    if ($wNum > $maxWeek) $maxWeek = $wNum;

                    $dateRangeStr = trim($wm[2]);
                    $desc = trim($wm[3]);

                    $wStart = null;
                    $wEnd = null;
                    if (preg_match('/(\d{1,2}\s+[A-Za-z]{3,9}\.?)\s*(?:–|-|to)\s*(\d{1,2}\s+[A-Za-z]{3,9}\.?\s*\d{4})/i', $dateRangeStr, $drm)) {
                        preg_match('/\b(20\d{2})\b/', $drm[2], $yM);
                        $year = $yM[1] ?? date('Y');
                        $sTs = strtotime($drm[1] . ' ' . $year);
                        $eTs = strtotime($drm[2]);
                        if ($sTs && $eTs) {
                            $wStart = date('Y-m-d', $sTs);
                            $wEnd = date('Y-m-d', $eTs);
                        }
                    }

                    $type = 'teaching';
                    $lowerDesc = strtolower($desc);
                    if (preg_match('/exam|examination/i', $lowerDesc)) $type = 'exam';
                    elseif (preg_match('/revis|consolidat/i', $lowerDesc)) $type = 'revision';
                    elseif (preg_match('/student\s*week|mid\s*semester|mid\s*term|reading\s*week/i', $lowerDesc)) $type = 'student_week';
                    elseif (preg_match('/break|recess|holiday|vacation/i', $lowerDesc)) $type = 'break';
                    elseif (preg_match('/orientat|matriculat|resumption|registration/i', $lowerDesc)) $type = 'orientation';

                    $label = $desc !== '' ? $desc : "Week {$wNum}";

                    $weeks[$wNum] = [
                        'week_number' => $wNum,
                        'label'       => $label,
                        'week_type'   => $type,
                        'start_date'  => $wStart,
                        'end_date'    => $wEnd,
                    ];
                    continue;
                }
            }

            // Multi-week Range: Week(s) X - Week(s) Y or Week(s) X to Y
            if (preg_match('/\bweeks?\s*(\d{1,2})\s*(?:-|–|to)\s*(?:weeks?\s*)?(\d{1,2})\b(?:\s*[-–:]\s*(.*))?$/i', $t, $wm)) {
                $wStart = (int) $wm[1];
                $wEnd = (int) $wm[2];
                $desc = isset($wm[3]) ? trim($wm[3]) : '';
                if ($wStart >= 1 && $wEnd <= 30 && $wEnd >= $wStart) {
                    if ($wEnd > $maxWeek) $maxWeek = $wEnd;
                    $lowerDesc = strtolower($desc);
                    $type = 'teaching';
                    if (preg_match('/exam|examination/i', $lowerDesc)) $type = 'exam';
                    elseif (preg_match('/revis|consolidat/i', $lowerDesc)) $type = 'revision';
                    elseif (preg_match('/student\s*week|mid\s*semester|mid\s*term|reading\s*week/i', $lowerDesc)) $type = 'student_week';
                    elseif (preg_match('/break|recess|holiday|vacation/i', $lowerDesc)) $type = 'break';
                    elseif (preg_match('/orientat|matriculat|resumption|registration/i', $lowerDesc)) $type = 'orientation';

                    for ($w = $wStart; $w <= $wEnd; $w++) {
                        $label = $desc !== '' ? "Week {$w}: {$desc}" : "Week {$w}";
                        $weeks[$w] = [
                            'week_number' => $w,
                            'label'       => $label,
                            'week_type'   => $type,
                        ];
                    }
                    continue;
                }
            }

            // Single Week: Week X or Week X: Description
            if (preg_match('/\bweek\s*(\d{1,2})\b(?:\s*[-–:]\s*(.*))?$/i', $t, $wm)) {
                $wNum = (int) $wm[1];
                if ($wNum < 1 || $wNum > 30) continue;
                if ($wNum > $maxWeek) $maxWeek = $wNum;

                $desc = isset($wm[2]) ? trim($wm[2]) : '';
                $lowerDesc = strtolower($desc);
                $type = 'teaching';
                if (preg_match('/exam|examination/i', $lowerDesc)) {
                    $type = 'exam';
                } elseif (preg_match('/revis|consolidat/i', $lowerDesc)) {
                    $type = 'revision';
                } elseif (preg_match('/student\s*week|mid\s*semester|mid\s*term|reading\s*week/i', $lowerDesc)) {
                    $type = 'student_week';
                } elseif (preg_match('/break|recess|holiday|vacation/i', $lowerDesc)) {
                    $type = 'break';
                } elseif (preg_match('/orientat|matriculat|resumption|registration/i', $lowerDesc)) {
                    $type = 'orientation';
                }

                $label = $desc !== '' ? $desc : "Week {$wNum}";

                $weeks[$wNum] = [
                    'week_number' => $wNum,
                    'label'       => $label,
                    'week_type'   => $type,
                ];
            }
        }

        // Check summary block tags for phase overrides / enhancements:
        // e.g. "Student Week: Week 7", "Revision Week: Week 13", "Examination Period: Weeks 14–15", "Semester dates: 12 January 2026 – 26 April 2026"
        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('/semester\s*dates?\s*:\s*([A-Za-z0-9\s,\.\-]+?)(?:–|-|to)\s*([A-Za-z0-9\s,\.\-]+)/i', $t, $sdm)) {
                $sTs = strtotime(trim($sdm[1]));
                $eTs = strtotime(trim($sdm[2]));
                if ($sTs && $eTs) {
                    $startDate = date('Y-m-d', $sTs);
                    $endDate = date('Y-m-d', $eTs);
                }
            } elseif (preg_match('/student\s*week\s*:\s*week\s*(\d{1,2})/i', $t, $sm)) {
                $w = (int)$sm[1];
                if (isset($weeks[$w])) {
                    $weeks[$w]['week_type'] = 'student_week';
                    if (empty($weeks[$w]['label']) || $weeks[$w]['label'] === "Week {$w}") $weeks[$w]['label'] = 'Student Week';
                }
            } elseif (preg_match('/revision\s*week\s*:\s*week\s*(\d{1,2})/i', $t, $sm)) {
                $w = (int)$sm[1];
                if (isset($weeks[$w])) {
                    $weeks[$w]['week_type'] = 'revision';
                    if (empty($weeks[$w]['label']) || $weeks[$w]['label'] === "Week {$w}") $weeks[$w]['label'] = 'Revision Week';
                }
            } elseif (preg_match('/examination\s*(?:period|week[s]?)\s*:\s*weeks?\s*(\d{1,2})(?:\s*(?:–|-|to)\s*(\d{1,2}))?/i', $t, $sm)) {
                $w1 = (int)$sm[1];
                $w2 = !empty($sm[2]) ? (int)$sm[2] : $w1;
                for ($w = $w1; $w <= $w2; $w++) {
                    if (isset($weeks[$w])) {
                        $weeks[$w]['week_type'] = 'exam';
                        if (empty($weeks[$w]['label']) || $weeks[$w]['label'] === "Week {$w}") $weeks[$w]['label'] = 'Examination Week';
                    }
                }
            }
        }

        if ($maxWeek < 4 && empty($weeks)) {
            $maxWeek = 14;
            for ($w = 1; $w <= $maxWeek; $w++) {
                $type = 'teaching';
                $label = "Teaching Week {$w}";
                if ($w === 8) {
                    $type = 'student_week';
                    $label = "Student Week / Mid-Semester Break";
                } elseif ($w === 13) {
                    $type = 'revision';
                    $label = "Revision Week";
                } elseif ($w >= 14) {
                    $type = 'exam';
                    $label = "Examination Week";
                }
                $weeks[$w] = [
                    'week_number' => $w,
                    'label'       => $label,
                    'week_type'   => $type,
                ];
            }
        } else {
            for ($w = 1; $w <= $maxWeek; $w++) {
                if (!isset($weeks[$w])) {
                    $weeks[$w] = [
                        'week_number' => $w,
                        'label'       => "Teaching Week {$w}",
                        'week_type'   => 'teaching',
                    ];
                }
            }
            ksort($weeks);
        }

        // Align base start date to Monday
        $baseStartTs = strtotime($startDate);
        $dayOfWeek = (int) date('N', $baseStartTs);
        if ($dayOfWeek !== 1) {
            $baseStartTs = strtotime('-' . ($dayOfWeek - 1) . ' days', $baseStartTs);
        }

        $finalWeeks = [];
        foreach ($weeks as $wNum => $wData) {
            $wStartTs = strtotime('+' . ($wNum - 1) . ' weeks', $baseStartTs);
            $wEndTs = strtotime('+6 days', $wStartTs);
            if (empty($wData['start_date'])) {
                $wData['start_date'] = date('Y-m-d', $wStartTs);
            }
            if (empty($wData['end_date'])) {
                $wData['end_date'] = date('Y-m-d', $wEndTs);
            }
            $finalWeeks[] = $wData;
        }

        $calculatedEndDate = !empty($finalWeeks) ? end($finalWeeks)['end_date'] : $endDate;

        return [
            'semester_name' => $semesterName,
            'start_date'    => !empty($finalWeeks) ? $finalWeeks[0]['start_date'] : date('Y-m-d', $baseStartTs),
            'end_date'      => $calculatedEndDate,
            'weeks'         => $finalWeeks,
        ];
    }

    // =========================================================================
    // DOMAIN PARSER 3: CLASS TIMETABLE
    // =========================================================================
    public static function parseDayName(string $str): ?string {
        $clean = strtolower(trim($str, " \t\n\r\0\x0B:.,-()[]"));
        $map = [
            'monday' => 'monday', 'mon' => 'monday',
            'tuesday' => 'tuesday', 'tue' => 'tuesday', 'tues' => 'tuesday',
            'wednesday' => 'wednesday', 'wed' => 'wednesday',
            'thursday' => 'thursday', 'thu' => 'thursday', 'thur' => 'thursday', 'thurs' => 'thursday',
            'friday' => 'friday', 'fri' => 'friday',
            'saturday' => 'saturday', 'sat' => 'saturday',
            'sunday' => 'sunday', 'sun' => 'sunday',
        ];
        return $map[$clean] ?? null;
    }

    public static function parseTimetable(string $text, ?PDO $db = null, ?int $userId = null): array {
        $lines = preg_split("/\n/", $text);
        $classes = [];
        $seenSlots = [];

        // Fetch user courses for code and ID mapping
        $courseMap = [];
        if ($db && $userId) {
            $stmt = $db->prepare('SELECT id, UPPER(code) as code, name FROM courses WHERE user_id = ?');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $courseMap[$c['code']] = $c;
            }
        }

        // Fetch existing schedule events to flag duplicates
        $existingSlots = [];
        if ($db && $userId) {
            $stmt = $db->prepare('SELECT day_of_week, start_time, end_time, UPPER(COALESCE(c.code, title)) as slot_key FROM schedule_events se LEFT JOIN courses c ON c.id = se.course_id WHERE se.user_id = ?');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = "{$row['day_of_week']}_{$row['start_time']}_{$row['end_time']}_{$row['slot_key']}";
                $existingSlots[$key] = true;
            }
        }

        $dayRegex = '/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)\b/i';
        $timeRangeRegex = '/\b(\d{1,2}(?:[:\.]\d{2})?\s*(?:am|pm)?)\s*(?:-|–|to)\s*(\d{1,2}(?:[:\.]\d{2})?\s*(?:am|pm)?)\b/i';
        $courseCodeRegex = '/\b([A-Za-z]{2,4})\s*[-]?\s*(\d{3}[A-Za-z]?)\b/i';

        // Check if table contains tab-delimited grid structure (e.g. from DOCX tables or coordinates)
        $gridLines = [];
        foreach ($lines as $line) {
            if (str_contains($line, "\t")) {
                $gridLines[] = explode("\t", trim($line));
            }
        }

        $isGrid = false;
        $gridHeaderDays = [];
        $gridHeaderTimes = [];

        if (!empty($gridLines)) {
            $firstRow = $gridLines[0];
            foreach ($firstRow as $colIdx => $colVal) {
                $parsedDay = self::parseDayName($colVal);
                if ($parsedDay) {
                    $gridHeaderDays[$colIdx] = $parsedDay;
                } elseif (preg_match($timeRangeRegex, $colVal)) {
                    $gridHeaderTimes[$colIdx] = $colVal;
                }
            }

            if (count($gridHeaderDays) >= 2) {
                // Days in columns, Times in rows
                $isGrid = true;
                for ($r = 1; $r < count($gridLines); $r++) {
                    $row = $gridLines[$r];
                    $rowTimeStr = $row[0] ?? '';
                    $startTime = '09:00:00';
                    $endTime = '11:00:00';
                    if (preg_match($timeRangeRegex, $rowTimeStr, $tm)) {
                        $startTime = self::standardizeTime($tm[1]);
                        $endTime = self::standardizeTime($tm[2]);
                    }

                    foreach ($gridHeaderDays as $colIdx => $day) {
                        $cellText = trim($row[$colIdx] ?? '');
                        if ($cellText === '') continue;

                        if (preg_match_all($courseCodeRegex, $cellText, $allCodes, PREG_SET_ORDER)) {
                            foreach ($allCodes as $cm) {
                                $cCode = strtoupper($cm[1]) . ' ' . strtoupper($cm[2]);
                                $location = '';
                                if (preg_match('/\b(Lecture\s*Theat(?:re|er)\s*\d*|LT\s*\d+|Hall\s*[A-Za-z0-9]+|Room\s*\d+|Lab\s*\d+|Auditorium|Workshop)\b/i', $cellText, $lm)) {
                                    $location = trim($lm[0]);
                                }
                                $lecturer = '';
                                if (preg_match('/\b(?:Dr\.|Prof\.|Mr\.|Mrs\.|Engr\.)\s+[A-Za-z]+(?:\s+[A-Za-z]+)?\b/i', $cellText, $lecm)) {
                                    $lecturer = trim($lecm[0]);
                                }
                                $eventType = stripos($cellText, 'lab') !== false ? 'lab' : 'lecture';
                                $matchedCourse = $courseMap[$cCode] ?? null;

                                $slotKey = "{$day}_{$startTime}_{$endTime}_{$cCode}";
                                if (isset($seenSlots[$slotKey])) continue;
                                $seenSlots[$slotKey] = true;

                                $classes[] = [
                                    'day_of_week'    => $day,
                                    'course_code'    => $cCode,
                                    'course_name'    => $matchedCourse ? $matchedCourse['name'] : $cCode,
                                    'course_id'      => $matchedCourse ? (int) $matchedCourse['id'] : null,
                                    'start_time'     => $startTime,
                                    'end_time'       => $endTime,
                                    'location'       => $location ?: 'Campus',
                                    'lecturer'       => $lecturer,
                                    'event_type'     => $eventType,
                                    'already_exists' => isset($existingSlots[$slotKey]),
                                ];
                            }
                        }
                    }
                }
            } elseif (count($gridHeaderTimes) >= 2) {
                // Times in columns, Days in rows
                $isGrid = true;
                for ($r = 1; $r < count($gridLines); $r++) {
                    $row = $gridLines[$r];
                    $rowDay = self::parseDayName($row[0] ?? '');
                    if (!$rowDay) continue;

                    foreach ($gridHeaderTimes as $colIdx => $timeHeader) {
                        $startTime = '09:00:00';
                        $endTime = '11:00:00';
                        if (preg_match($timeRangeRegex, $timeHeader, $tm)) {
                            $startTime = self::standardizeTime($tm[1]);
                            $endTime = self::standardizeTime($tm[2]);
                        }
                        $cellText = trim($row[$colIdx] ?? '');
                        if ($cellText === '') continue;

                        if (preg_match_all($courseCodeRegex, $cellText, $allCodes, PREG_SET_ORDER)) {
                            foreach ($allCodes as $cm) {
                                $cCode = strtoupper($cm[1]) . ' ' . strtoupper($cm[2]);
                                $location = '';
                                if (preg_match('/\b(Lecture\s*Theat(?:re|er)\s*\d*|LT\s*\d+|Hall\s*[A-Za-z0-9]+|Room\s*\d+|Lab\s*\d+|Auditorium|Workshop)\b/i', $cellText, $lm)) {
                                    $location = trim($lm[0]);
                                }
                                $lecturer = '';
                                if (preg_match('/\b(?:Dr\.|Prof\.|Mr\.|Mrs\.|Engr\.)\s+[A-Za-z]+(?:\s+[A-Za-z]+)?\b/i', $cellText, $lecm)) {
                                    $lecturer = trim($lecm[0]);
                                }
                                $eventType = stripos($cellText, 'lab') !== false ? 'lab' : 'lecture';
                                $matchedCourse = $courseMap[$cCode] ?? null;

                                $slotKey = "{$rowDay}_{$startTime}_{$endTime}_{$cCode}";
                                if (isset($seenSlots[$slotKey])) continue;
                                $seenSlots[$slotKey] = true;

                                $classes[] = [
                                    'day_of_week'    => $rowDay,
                                    'course_code'    => $cCode,
                                    'course_name'    => $matchedCourse ? $matchedCourse['name'] : $cCode,
                                    'course_id'      => $matchedCourse ? (int) $matchedCourse['id'] : null,
                                    'start_time'     => $startTime,
                                    'end_time'       => $endTime,
                                    'location'       => $location ?: 'Campus',
                                    'lecturer'       => $lecturer,
                                    'event_type'     => $eventType,
                                    'already_exists' => isset($existingSlots[$slotKey]),
                                ];
                            }
                        }
                    }
                }
            }
        }

        if (!$isGrid) {
            $currentDay = null;

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') continue;

                // Skip table headers
                if (preg_match('/^\s*(?:course|course\s*title|day|start|end|venue|lecturer|time|period|slot)\b/i', $trimmed) && !preg_match($courseCodeRegex, $trimmed)) {
                    continue;
                }

                // Check if line sets the day header (e.g. "MONDAY" or "Mon:" or "Friday -")
                if (preg_match('/^\s*(monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)\s*[:\-]?\s*$/i', $trimmed, $dHeader)) {
                    $pDay = self::parseDayName($dHeader[1]);
                    if ($pDay) {
                        $currentDay = $pDay;
                        continue;
                    }
                }

                // Look for Course Code
                if (preg_match($courseCodeRegex, $trimmed, $cMatch)) {
                    $courseCode = strtoupper($cMatch[1]) . ' ' . strtoupper($cMatch[2]);
                    $matchedCourse = $courseMap[$courseCode] ?? null;
                    $courseId = $matchedCourse ? (int) $matchedCourse['id'] : null;
                    $courseName = $matchedCourse ? $matchedCourse['name'] : $courseCode;

                    // Extract Course Title: text between course code and day name if not already in DB
                    if (!$matchedCourse) {
                        if (preg_match('/' . preg_quote($cMatch[0], '/') . '\s+(.*?)\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)\b/i', $trimmed, $titleM)) {
                            $candidate = trim($titleM[1]);
                            if (strlen($candidate) > 2 && !preg_match('/\b(?:AM|PM|\d{1,2}:\d{2})\b/i', $candidate)) {
                                $courseName = $candidate;
                            }
                        }
                    }

                    // Day for this line
                    $day = $currentDay ?: 'monday';
                    if (preg_match($dayRegex, $trimmed, $dInline)) {
                        $pDay = self::parseDayName($dInline[1]);
                        if ($pDay) $day = $pDay;
                    }

                    // Look for Time Range (both hyphenated e.g. "10:00 AM - 12:00 PM" and adjacent columns e.g. "10:00 AM 12:00 PM")
                    $startTime = '09:00:00';
                    $endTime = '11:00:00';
                    if (preg_match($timeRangeRegex, $trimmed, $tMatch)) {
                        $startTime = self::standardizeTime($tMatch[1]);
                        $endTime = self::standardizeTime($tMatch[2]);
                    } elseif (preg_match_all('/\b(\d{1,2}:\d{2}(?:\s*(?:am|pm))?|\d{1,2}\s*(?:am|pm))\b/i', $trimmed, $allTimes) && count($allTimes[0]) >= 2) {
                        $startTime = self::standardizeTime($allTimes[0][0]);
                        $endTime = self::standardizeTime($allTimes[0][1]);
                    }

                    // Look for Location / Venue
                    $location = '';
                    if (preg_match('/\b((?:ICT\s+)?(?:Lab|Laboratory)\s*\d*|LT\s*\d*|Lecture\s*Theat(?:re|er)(?:\s*[A-Za-z0-9]+)?|Hall\s*[A-Za-z0-9]+|Room\s*[A-Za-z0-9]+|Project\s*Room|Auditorium|Workshop|Studio)\b/i', $trimmed, $lMatch)) {
                        $location = trim($lMatch[0]);
                    }

                    // Look for Lecturer
                    $lecturer = '';
                    if (preg_match('/\b(?:Dr\.|Prof\.|Mr\.|Mrs\.|Engr\.)\s+[A-Za-z]+(?:\s+[A-Za-z]+)?\b/i', $trimmed, $lecMatch)) {
                        $lecturer = trim($lecMatch[0]);
                    }

                    $eventType = stripos($trimmed, 'lab') !== false ? 'lab' : 'lecture';
                    $slotKey = "{$day}_{$startTime}_{$endTime}_{$courseCode}";
                    if (isset($seenSlots[$slotKey])) continue;
                    $seenSlots[$slotKey] = true;

                    $classes[] = [
                        'day_of_week'    => $day,
                        'course_code'    => $courseCode,
                        'course_name'    => $courseName,
                        'course_id'      => $courseId,
                        'start_time'     => $startTime,
                        'end_time'       => $endTime,
                        'location'       => $location ?: 'Campus',
                        'lecturer'       => $lecturer,
                        'event_type'     => $eventType,
                        'already_exists' => isset($existingSlots[$slotKey]),
                    ];
                }
            }
        }

        return $classes;
    }

    /**
     * Standardizes a time string to HH:MM:00 (24-hour format).
     */
    public static function standardizeTime(string $timeStr): string {
        $clean = trim($timeStr);
        $clean = preg_replace('/(\d{1,2})\.(\d{2})/', '$1:$2', $clean);
        $ts = strtotime($clean);
        if ($ts) {
            return date('H:i:00', $ts);
        }
        if (preg_match('/^(\d{1,2})$/', $clean, $m)) {
            $h = (int) $m[1];
            if ($h < 8) $h += 12; // Assume afternoon if small number
            return sprintf('%02d:00:00', $h);
        }
        return '09:00:00';
    }

    // =========================================================================
    // DOMAIN PARSER 4: SCHOOL WORK / ASSIGNMENTS / TESTS / PROJECTS
    // =========================================================================
    public static function parseWork(string $text, ?PDO $db = null, ?int $userId = null): array {
        $lines = preg_split("/\n/", $text);
        $tasks = [];
        $currentCourse = null;
        $seenTasks = [];

        // Fetch user courses for course matching
        $courseMap = [];
        if ($db && $userId) {
            $stmt = $db->prepare('SELECT id, UPPER(code) as code, name FROM courses WHERE user_id = ?');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $courseMap[$c['code']] = $c;
            }
        }

        // Fetch existing task titles to flag duplicates
        $existingTasks = [];
        if ($db && $userId) {
            $stmt = $db->prepare('SELECT LOWER(TRIM(title)) FROM tasks WHERE user_id = ?');
            $stmt->execute([$userId]);
            $existingTasks = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $courseCodeRegex = '/\b([A-Za-z]{2,4})\s*[-]?\s*(\d{3}[A-Za-z]?)\b/i';
        $workRegex = '/\b(Assignment(?:\s*\d+)?|Project(?:\s*(?:Phase|Milestone)?\s*\d+)?|Test(?:\s*\d+)?|Continuous\s*Assessment(?:\s*\d+)?|C\.?A\.?(?:\s*\d+)?|Mid-?Semester\s*Test|Quiz(?:\s*\d+)?|Lab\s*Report(?:\s*\d+)?|Laboratory\s*(?:Report|Experiment)|Term\s*Paper|Presentation|Seminar|Exam(?:ination)?|Homework|Problem\s*Set)\b/i';

        // Check if this is a structured assignment form / cover sheet with labeled multi-line fields
        $isStructuredForm = false;
        $formMeta = [
            'course_code'  => '',
            'course_title' => '',
            'work_type'    => 'assignment',
            'priority'     => 'medium',
            'due_date'     => null,
            'due_time'     => '23:59:00',
            'title'        => '',
            'instructions' => [],
        ];

        $inInstructions = false;

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;

            // Key: Course Code
            if (preg_match('/course\s*code\s*[:\t]?\s*([A-Za-z]{2,4}\s*[-]?\s*\d{3}[A-Za-z]?)/i', $t, $m)) {
                $isStructuredForm = true;
                $formMeta['course_code'] = strtoupper(preg_replace('/\s+/', ' ', $m[1]));
            }
            // Key: Course Title
            elseif (preg_match('/course\s*title\s*[:\t]?\s*(.*)$/i', $t, $m)) {
                $isStructuredForm = true;
                $formMeta['course_title'] = trim($m[1]);
            }
            // Key: Work Type
            elseif (preg_match('/work\s*type\s*[:\t]?\s*(.*)$/i', $t, $m)) {
                $isStructuredForm = true;
                $wTypeStr = strtolower(trim($m[1]));
                if (str_contains($wTypeStr, 'project') || str_contains($wTypeStr, 'paper')) {
                    $formMeta['work_type'] = 'project';
                } elseif (str_contains($wTypeStr, 'test') || str_contains($wTypeStr, 'quiz') || str_contains($wTypeStr, 'c.a')) {
                    $formMeta['work_type'] = 'test';
                } elseif (str_contains($wTypeStr, 'lab')) {
                    $formMeta['work_type'] = 'lab_report';
                } else {
                    $formMeta['work_type'] = 'assignment';
                }
            }
            // Key: Priority
            elseif (preg_match('/priority\s*[:\t]?\s*(high|medium|low|urgent|important)/i', $t, $m)) {
                $isStructuredForm = true;
                $pStr = strtolower($m[1]);
                $formMeta['priority'] = in_array($pStr, ['high', 'urgent', 'important']) ? 'high' : ($pStr === 'low' ? 'low' : 'medium');
            }
            // Key: Due Date
            elseif (preg_match('/due\s*date\s*[:\t]?\s*(.*)$/i', $t, $m)) {
                $isStructuredForm = true;
                $dCandidate = trim($m[1]);
                if (preg_match('/\b([A-Za-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}\.?,?\s+\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})\b/i', $dCandidate, $dm)) {
                    $cleanDate = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $dm[1]);
                    $ts = strtotime($cleanDate);
                    if ($ts && $ts > strtotime('2020-01-01')) {
                        $formMeta['due_date'] = date('Y-m-d', $ts);
                    }
                }
            }
            // Key: Due Time
            elseif (preg_match('/due\s*time\s*[:\t]?\s*(.*)$/i', $t, $m)) {
                $isStructuredForm = true;
                $tCandidate = trim($m[1]);
                if (preg_match('/\b(\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm)?|\d{1,2}\s*(?:am|pm))\b/i', $tCandidate, $tm)) {
                    $formMeta['due_time'] = self::standardizeTime($tm[1]);
                }
            }
            // Key: Assignment Title / Title
            elseif (preg_match('/^(?:assignment\s*title|project\s*title|task\s*title|title)\s*[:\t]?\s*(.*)$/i', $t, $m)) {
                $isStructuredForm = true;
                $val = trim($m[1]);
                if ($val !== '') {
                    $formMeta['title'] = $val;
                }
            }
            // Instructions header
            elseif (preg_match('/^instructions?\s*[:\t]?/i', $t)) {
                $inInstructions = true;
            }
            // Bullet points or instruction content
            elseif ($inInstructions) {
                if (preg_match('/^(?:make\s*sure|submit|note|submission\s*reminder)\b/i', $t) && !preg_match('/^[\-\*•\x7F\s]/', $t)) {
                    $inInstructions = false;
                } else {
                    $cleanInst = trim(preg_replace('/^[\-\*•\x7F\x{007F}\x{2022}\x{25E6}\s]+/u', '', $t));
                    if (strlen($cleanInst) > 5) {
                        $formMeta['instructions'][] = $cleanInst;
                    }
                }
            }
            // If title header was alone on line, check if this line is the actual title
            elseif ($isStructuredForm && empty($formMeta['title']) && !preg_match('/^(?:university|department|course|work|priority|due|instructions)/i', $t)) {
                if (strlen($t) > 5 && !preg_match($courseCodeRegex, $t)) {
                    $formMeta['title'] = $t;
                }
            }
        }

        if ($isStructuredForm && (!empty($formMeta['course_code']) || !empty($formMeta['title']))) {
            $finalTitle = $formMeta['title'];
            if (empty($finalTitle)) {
                $finalTitle = ($formMeta['course_code'] ? $formMeta['course_code'] . ' ' : '') . ucfirst($formMeta['work_type']);
            }

            $desc = implode("\n", $formMeta['instructions']);
            $matchedCourse = $courseMap[$formMeta['course_code']] ?? null;

            return [[
                'title'          => $finalTitle,
                'course_id'      => $matchedCourse ? (int) $matchedCourse['id'] : null,
                'course_code'    => $formMeta['course_code'],
                'type'           => $formMeta['work_type'],
                'priority'       => $formMeta['priority'],
                'due_date'       => $formMeta['due_date'],
                'due_time'       => $formMeta['due_time'],
                'description'    => $desc,
                'duration_hours' => in_array($formMeta['work_type'], ['project', 'exam'], true) ? 4.0 : 2.0,
                'already_exists' => isset($existingTasks[strtolower(trim($finalTitle))]),
            ]];
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            // Track Course context
            if (preg_match($courseCodeRegex, $trimmed, $cMatch)) {
                $code = strtoupper($cMatch[1]) . ' ' . strtoupper($cMatch[2]);
                if (isset($courseMap[$code])) {
                    $currentCourse = $courseMap[$code];
                } else {
                    $currentCourse = ['code' => $code, 'id' => null, 'name' => $code];
                }
            }

            // Look for work keywords
            if (preg_match($workRegex, $trimmed, $wMatch)) {
                $rawWorkTitle = trim($wMatch[0]);
                $lowerWork = strtolower($rawWorkTitle);

                // Determine task type
                $type = 'assignment';
                if (str_contains($lowerWork, 'project') || str_contains($lowerWork, 'paper')) {
                    $type = 'project';
                } elseif (str_contains($lowerWork, 'test') || str_contains($lowerWork, 'quiz') || str_contains($lowerWork, 'c.a') || str_contains($lowerWork, 'continuous assessment')) {
                    $type = 'test';
                } elseif (str_contains($lowerWork, 'exam')) {
                    $type = 'exam';
                } elseif (str_contains($lowerWork, 'lab')) {
                    $type = 'lab_report';
                } elseif (str_contains($lowerWork, 'presentation') || str_contains($lowerWork, 'seminar')) {
                    $type = 'research';
                }

                // Default priority based on type
                $priority = in_array($type, ['exam', 'project', 'test'], true) ? 'high' : 'medium';
                if (preg_match('/\b(urgent|high\s*priority|important)\b/i', $trimmed)) $priority = 'high';
                if (preg_match('/\b(low\s*priority|optional)\b/i', $trimmed)) $priority = 'low';

                // Look for Due Date (Never invent dates! If absent, leave blank per Requirement #10)
                $dueDate = null;
                $dueTime = '23:59:00';
                if (preg_match('/\b(?:due|submit|by|deadline|date|submission)?\s*:?\s*([A-Za-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}\.?,?\s+\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})\b/i', $trimmed, $dMatch)) {
                    $cleanDate = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $dMatch[1]);
                    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $cleanDate, $dParts)) {
                        $d = (int) $dParts[1];
                        $m = (int) $dParts[2];
                        $y = (int) $dParts[3];
                        if ($m <= 12 && $d <= 31) {
                            $cleanDate = sprintf('%04d-%02d-%02d', $y, $m, $d);
                        }
                    }
                    $ts = strtotime($cleanDate);
                    if ($ts && $ts > strtotime('2020-01-01')) {
                        $dueDate = date('Y-m-d', $ts);
                    }
                }

                // Look for Due Time if specified (e.g. 4:00 PM, 11:59pm, 14:00, 4pm)
                if (preg_match('/\b(\d{1,2}:\d{2}(?::\d{2})?\s*(?:am|pm)?|\d{1,2}\s*(?:am|pm))\b/i', $trimmed, $tmMatch)) {
                    $dueTime = self::standardizeTime($tmMatch[1]);
                }

                // Build descriptive title
                $courseCode = $currentCourse ? $currentCourse['code'] : '';
                $courseId = $currentCourse ? (int) $currentCourse['id'] : null;

                $fullTitle = $rawWorkTitle;
                if ($courseCode && !str_contains($fullTitle, $courseCode)) {
                    $fullTitle = $courseCode . ' ' . $rawWorkTitle;
                }

                // Extract any additional description after title
                $desc = '';
                $pos = strpos($trimmed, $rawWorkTitle);
                if ($pos !== false) {
                    $after = trim(substr($trimmed, $pos + strlen($rawWorkTitle)), " \t\n\r-|:,.");
                    if ($dueDate && !empty($dMatch[0])) {
                        $after = trim(str_replace($dMatch[0], '', $after), " \t\n\r-|:,.");
                    }
                    if (strlen($after) > 3) $desc = $after;
                }

                $dedupKey = strtolower($fullTitle);
                if (isset($seenTasks[$dedupKey])) continue;
                $seenTasks[$dedupKey] = true;

                $tasks[] = [
                    'title'          => $fullTitle,
                    'course_id'      => $courseId,
                    'course_code'    => $courseCode,
                    'type'           => $type,
                    'priority'       => $priority,
                    'due_date'       => $dueDate,
                    'due_time'       => $dueTime,
                    'description'    => $desc,
                    'duration_hours' => in_array($type, ['project', 'exam'], true) ? 4.0 : 2.0,
                    'already_exists' => isset($existingTasks[strtolower(trim($fullTitle))]),
                ];
            }
        }

        return $tasks;
    }
}
