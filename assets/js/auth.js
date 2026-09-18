const API = '../api';


// ============================================================
// HELPERS
// ============================================================

function showError(message) {

  const el =
    document.getElementById('form-error');

  if (!el) return;

  el.textContent =
    message || 'Something went wrong.';

  el.classList.remove('hidden');
}


function showSuccess(message) {

  const el =
    document.getElementById('form-success');

  if (!el) return;

  el.textContent =
    message || '';

  el.classList.remove('hidden');
}


function hideMessages() {

  const error =
    document.getElementById('form-error');

  const success =
    document.getElementById('form-success');

  if (error) {

    error.textContent = '';

    error.classList.add('hidden');
  }

  if (success) {

    success.textContent = '';

    success.classList.add('hidden');
  }
}


function setLoading(
  button,
  loading,
  text
) {

  if (!button) return;

  if (loading) {

    if (!button.dataset.originalText) {

      button.dataset.originalText =
        button.textContent;
    }

    button.textContent =
      text;

    button.disabled =
      true;

    button.classList.add(
      'opacity-70',
      'cursor-not-allowed'
    );

  } else {

    button.textContent =
      button.dataset.originalText ||
      button.textContent;

    button.disabled =
      false;

    button.classList.remove(
      'opacity-70',
      'cursor-not-allowed'
    );
  }
}


// ============================================================
// JSON RESPONSE HANDLER
// ============================================================

async function getJsonResponse(response) {

  const text =
    await response.text();


  console.log(
    'API STATUS:',
    response.status
  );


  console.log(
    'API RESPONSE:',
    text
  );


  if (!text.trim()) {

    throw new Error(
      `The server returned an empty response (HTTP ${response.status}).`
    );
  }


  let data;

  try {

    data =
      JSON.parse(text);

  } catch (error) {

    console.error(
      'Invalid JSON received from server:',
      text
    );

    throw new Error(
      `The server returned invalid JSON (HTTP ${response.status}).`
    );
  }


  return data;
}


// ============================================================
// LOGIN
// ============================================================

const loginForm =
  document.getElementById(
    'login-form'
  );


if (loginForm) {

  loginForm.addEventListener(
    'submit',
    async (e) => {

      e.preventDefault();

      hideMessages();


      const button =
        document.getElementById(
          'login-submit'
        );


      const formData =
        new FormData(
          loginForm
        );


      const email =
        String(
          formData.get('email') || ''
        ).trim();


      const password =
        String(
          formData.get('password') || ''
        );


      if (!email || !password) {

        showError(
          'Please enter your email and password.'
        );

        return;
      }


      setLoading(
        button,
        true,
        'Signing in...'
      );


      try {

        const response =
          await fetch(
            `${API}/login.php`,
            {
              method: 'POST',

              headers: {
                'Content-Type':
                  'application/json',

                'Accept':
                  'application/json'
              },

              credentials:
                'same-origin',

              cache:
                'no-store',

              body:
                JSON.stringify({
                  email:
                    email,

                  password:
                    password
                })
            }
          );


        const data =
          await getJsonResponse(
            response
          );


        if (
          response.ok &&
          data.ok
        ) {

          window.location.replace(
            './dashboard.php'
          );

          return;
        }


        showError(
          data.error ||
          'Incorrect email or password.'
        );


      } catch (error) {

        console.error(
          'Login error:',
          error
        );


        showError(
          error.message ||
          'Unable to connect to the server. Please try again.'
        );


      } finally {

        setLoading(
          button,
          false
        );
      }
    }
  );
}


// ============================================================
// REGISTER
// ============================================================

const registerForm =
  document.getElementById(
    'register-form'
  );


if (registerForm) {

  registerForm.addEventListener(
    'submit',
    async (e) => {

      e.preventDefault();

      hideMessages();


      const button =
        document.getElementById(
          'register-submit'
        );


      const formData =
        new FormData(
          registerForm
        );


      setLoading(
        button,
        true,
        'Creating account...'
      );


      try {

        const response =
          await fetch(
            `${API}/register.php`,
            {
              method: 'POST',

              headers: {
                'Content-Type':
                  'application/json',

                'Accept':
                  'application/json'
              },

              credentials:
                'same-origin',

              cache:
                'no-store',

              body:
                JSON.stringify(
                  Object.fromEntries(
                    formData.entries()
                  )
                )
            }
          );


        const data =
          await getJsonResponse(
            response
          );


        if (
          response.ok &&
          data.ok
        ) {

          window.location.replace(
            './dashboard.php'
          );

          return;
        }


        showError(
          data.error ||
          'Could not create account.'
        );


      } catch (error) {

        console.error(
          'Registration error:',
          error
        );


        showError(
          error.message ||
          'Unable to connect to the server. Please try again.'
        );


      } finally {

        setLoading(
          button,
          false
        );
      }
    }
  );
}


// ============================================================
// FORGOT PASSWORD
// ============================================================

const forgotPasswordForm =
  document.getElementById(
    'forgot-password-form'
  );


if (forgotPasswordForm) {

  forgotPasswordForm.addEventListener(
    'submit',
    async (e) => {

      e.preventDefault();

      hideMessages();


      const button =
        document.getElementById(
          'forgot-submit'
        );


      const emailInput =
        forgotPasswordForm.querySelector(
          '[name="email"]'
        );


      const email =
        String(
          emailInput?.value || ''
        ).trim();


      if (!email) {

        showError(
          'Please enter your email address.'
        );

        return;
      }


      if (
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/
          .test(email)
      ) {

        showError(
          'Please enter a valid email address.'
        );

        return;
      }


      setLoading(
        button,
        true,
        'Sending reset link...'
      );


      try {

        const response =
          await fetch(
            `${API}/forgot-password.php`,
            {
              method: 'POST',

              headers: {
                'Content-Type':
                  'application/json',

                'Accept':
                  'application/json'
              },

              credentials:
                'same-origin',

              cache:
                'no-store',

              body:
                JSON.stringify({
                  email:
                    email
                })
            }
          );


        const data =
          await getJsonResponse(
            response
          );


        if (
          response.ok &&
          data.ok
        ) {

          forgotPasswordForm
            .classList
            .add('hidden');


          const success =
            document.getElementById(
              'success-state'
            );


          if (success) {

            success.classList.remove(
              'hidden'
            );

            if (data.reset_url) {
              let testLink = success.querySelector('#dev-reset-link');
              if (!testLink) {
                testLink = document.createElement('div');
                testLink.id = 'dev-reset-link';
                testLink.className = 'mt-4 p-3 bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800 rounded-lg text-xs text-blue-800 dark:text-blue-200 break-all';
                success.appendChild(testLink);
              }
              testLink.innerHTML = `<strong>Testing Link:</strong><br><a href="${data.reset_url}" class="underline font-semibold hover:text-blue-600 dark:hover:text-blue-400">Click here to reset password &rarr;</a>`;
            }
          }


          return;
        }


        showError(
          data.error ||
          'Unable to process your request.'
        );


      } catch (error) {

        console.error(
          'Forgot password error:',
          error
        );


        showError(
          error.message ||
          'Unable to connect to the server. Please try again.'
        );


      } finally {

        setLoading(
          button,
          false
        );
      }
    }
  );
}


// ============================================================
// RESET PASSWORD
// ============================================================

const resetPasswordForm =
  document.getElementById(
    'reset-password-form'
  );


if (resetPasswordForm) {

  resetPasswordForm.addEventListener(
    'submit',
    async (e) => {

      e.preventDefault();

      hideMessages();


      const button =
        document.getElementById(
          'reset-submit'
        );


      const password =
        document.getElementById(
          'new-password'
        );


      const confirmPassword =
        document.getElementById(
          'confirm-password'
        );


      const tokenInput =
        resetPasswordForm.querySelector(
          '[name="token"]'
        );


      const token =
        String(
          tokenInput?.value || ''
        ).trim();


      if (
        !password ||
        !confirmPassword
      ) {

        showError(
          'Password fields are missing.'
        );

        return;
      }


      if (!token) {

        showError(
          'This password reset link is invalid.'
        );

        return;
      }


      if (
        password.value.length < 8
      ) {

        showError(
          'Password must be at least 8 characters long.'
        );

        return;
      }


      if (
        password.value !==
        confirmPassword.value
      ) {

        showError(
          'Passwords do not match.'
        );

        return;
      }


      setLoading(
        button,
        true,
        'Resetting password...'
      );


      try {

        const response =
          await fetch(
            `${API}/reset-password.php`,
            {
              method: 'POST',

              headers: {
                'Content-Type':
                  'application/json',

                'Accept':
                  'application/json'
              },

              credentials:
                'same-origin',

              cache:
                'no-store',

              body:
                JSON.stringify({
                  token:
                    token,

                  password:
                    password.value,

                  confirm_password:
                    confirmPassword.value
                })
            }
          );


        const data =
          await getJsonResponse(
            response
          );


        if (
          response.ok &&
          data.ok
        ) {

          const container =
            document.getElementById(
              'reset-form-container'
            );


          const success =
            document.getElementById(
              'reset-success'
            );


          if (container) {

            container.classList.add(
              'hidden'
            );
          }


          if (success) {

            success.classList.remove(
              'hidden'
            );
          }


          return;
        }


        showError(
          data.error ||
          'Unable to reset your password.'
        );


      } catch (error) {

        console.error(
          'Reset password error:',
          error
        );


        showError(
          error.message ||
          'Unable to connect to the server. Please try again.'
        );


      } finally {

        setLoading(
          button,
          false
        );
      }
    }
  );
}


// ============================================================
// PASSWORD MATCH
// ============================================================

const newPassword =
  document.getElementById(
    'new-password'
  );


const confirmPassword =
  document.getElementById(
    'confirm-password'
  );


const passwordMatch =
  document.getElementById(
    'password-match'
  );


if (
  newPassword &&
  confirmPassword &&
  passwordMatch
) {

  function checkPasswordMatch() {

    if (!confirmPassword.value) {

      passwordMatch.textContent =
        '';

      return;
    }


    if (
      newPassword.value ===
      confirmPassword.value
    ) {

      passwordMatch.textContent =
        'Passwords match.';

      passwordMatch.className =
        'text-xs text-green-600 mt-1';

    } else {

      passwordMatch.textContent =
        'Passwords do not match.';

      passwordMatch.className =
        'text-xs text-red-500 mt-1';
    }
  }


  newPassword.addEventListener(
    'input',
    checkPasswordMatch
  );


  confirmPassword.addEventListener(
    'input',
    checkPasswordMatch
  );
}