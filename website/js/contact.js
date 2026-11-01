(function () {
  var form = document.getElementById("contact-form");
  if (!form) {
    return;
  }

  var success = document.getElementById("contact-success");
  var error = document.getElementById("contact-error");
  var errorText = document.getElementById("contact-error-text");
  var feedbackFooter = document.getElementById("contact-form-footer");
  var csrfInput = document.getElementById("csrf-token");
  var formTsInput = document.getElementById("form-ts");
  var submitButton = document.getElementById("contact-submit");
  var resetButton = document.getElementById("contact-reset");
  var fields = form.querySelectorAll("input:not([type='hidden']), textarea");
  var defaultButtonLabel = submitButton ? submitButton.textContent : "Send message";

  function scrollToFeedback() {
    if (feedbackFooter && feedbackFooter.scrollIntoView) {
      feedbackFooter.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }

  function setFieldsDisabled(disabled) {
    fields.forEach(function (field) {
      field.disabled = disabled;
    });
  }

  function showSuccess(message) {
    if (success) {
      success.textContent = message;
      success.classList.remove("hidden");
    }
    if (error) {
      error.classList.add("hidden");
    }
    setFieldsDisabled(true);
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = "Message sent";
      submitButton.classList.remove("bg-primary", "hover:bg-blue-600");
      submitButton.classList.add("bg-gray-400");
    }
    if (resetButton) {
      resetButton.classList.remove("hidden");
    }
    scrollToFeedback();
  }

  function showError(message) {
    if (error) {
      if (errorText) {
        errorText.textContent = message;
      } else {
        error.textContent = message;
      }
      error.classList.remove("hidden");
    }
    if (success) {
      success.classList.add("hidden");
    }
    setFieldsDisabled(false);
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = defaultButtonLabel;
      submitButton.classList.add("bg-primary", "hover:bg-blue-600");
      submitButton.classList.remove("bg-gray-400");
    }
    if (resetButton) {
      resetButton.classList.add("hidden");
    }
    scrollToFeedback();
  }

  function resetFormState() {
    if (success) {
      success.classList.add("hidden");
    }
    if (error) {
      error.classList.add("hidden");
    }
    setFieldsDisabled(false);
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = defaultButtonLabel;
      submitButton.classList.add("bg-primary", "hover:bg-blue-600");
      submitButton.classList.remove("bg-gray-400");
    }
    if (resetButton) {
      resetButton.classList.add("hidden");
    }
    form.reset();
  }

  function loadFormToken() {
    return fetch("contact-token.php", {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("token");
        }
        return response.json();
      })
      .then(function (data) {
        if (csrfInput && data.csrf_token) {
          csrfInput.value = data.csrf_token;
        }
        if (formTsInput && data.form_ts) {
          formTsInput.value = String(data.form_ts);
        }
      });
  }

  function parseJsonResponse(response) {
    var contentType = response.headers.get("Content-Type") || "";
    if (!contentType.includes("application/json")) {
      return Promise.reject(new Error("not-json"));
    }
    return response.json();
  }

  if (resetButton) {
    resetButton.addEventListener("click", function () {
      resetFormState();
      loadFormToken().catch(function () {
        showError(
          "Could not reset the form. Refresh the page or email support@recordplus.app directly."
        );
      });
    });
  }

  loadFormToken().catch(function () {
    showError(
      "Could not initialize the form. Refresh the page or email support@recordplus.app directly."
    );
    if (submitButton) {
      submitButton.disabled = true;
    }
  });

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    event.stopPropagation();

    if (!csrfInput || !csrfInput.value) {
      showError("Security token missing. Please refresh the page and try again.");
      return;
    }

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = "Sending…";
      submitButton.classList.add("bg-primary", "hover:bg-blue-600");
      submitButton.classList.remove("bg-gray-400");
    }

    if (error) {
      error.classList.add("hidden");
    }
    if (success) {
      success.classList.add("hidden");
    }

    fetch("contact.php", {
      method: "POST",
      body: new FormData(form),
      credentials: "same-origin",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
      },
    })
      .then(function (response) {
        return parseJsonResponse(response).then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (result.ok && result.data.success) {
          showSuccess(
            result.data.message ||
              "Thank you. Your message has been sent. We will reply to your email as soon as we can."
          );
          return loadFormToken();
        }

        showError(
          (result.data && result.data.message) ||
            "We could not send your message. Please email support@recordplus.app directly."
        );
        return loadFormToken();
      })
      .catch(function () {
        showError(
          "Could not send your message. Please try again or email support@recordplus.app directly."
        );
        return loadFormToken();
      })
      .finally(function () {
        if (submitButton && success && success.classList.contains("hidden")) {
          submitButton.disabled = false;
          submitButton.textContent = defaultButtonLabel;
        }
      });
  });
})();
