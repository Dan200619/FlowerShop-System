document.querySelectorAll("[data-password-reset-form]").forEach(function (form) {
    const dialog = form.closest("dialog");
    const requestStep = form.querySelector('[data-reset-step="request"]');
    const verifyStep = form.querySelector('[data-reset-step="verify"]');
    const emailInput = form.querySelector('[name="email"]');
    const pinInput = form.querySelector('[name="pin"]');
    const passwordInput = form.querySelector('[name="new_password"]');
    const confirmPasswordInput = form.querySelector('[name="confirm_password"]');
    const message = form.querySelector("[data-password-reset-message]");
    const sendButton = form.querySelector("[data-password-reset-send]");

    function showMessage(text, isError) {
        message.textContent = text;
        message.classList.toggle("is-error", isError);
        message.classList.toggle("is-success", !isError && text !== "");
    }

    function showRequestStep() {
        requestStep.hidden = false;
        verifyStep.hidden = true;
        requestStep.querySelectorAll("input").forEach(function (input) {
            input.disabled = false;
        });
        verifyStep.querySelectorAll("input").forEach(function (input) {
            input.disabled = true;
        });
        showMessage("", false);
    }

    function showVerifyStep() {
        requestStep.hidden = true;
        verifyStep.hidden = false;
        requestStep.querySelectorAll("input").forEach(function (input) {
            input.disabled = true;
        });
        verifyStep.querySelectorAll("input").forEach(function (input) {
            input.disabled = false;
        });
    }

    async function submitRecovery(action) {
        const payload = {
            action: action,
            account_type: form.dataset.accountType,
            csrf_token: form.dataset.csrfToken,
            email: emailInput.value.trim()
        };
        if (action === "reset_password") {
            payload.pin = pinInput.value.trim();
            payload.new_password = passwordInput.value;
            payload.confirm_password = confirmPasswordInput.value;
        }

        const response = await fetch("ForgotPassword.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify(payload)
        });
        const responseText = await response.text();
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (error) {
            throw new Error("The server returned an invalid response. Check the server error log and try again.");
        }
        if (!response.ok) {
            throw new Error(result.error || "Password recovery is temporarily unavailable.");
        }
        return result;
    }

    document.querySelectorAll("#open-password-reset, #open-customer-password-reset").forEach(function (button) {
        button.addEventListener("click", function () {
            form.reset();
            showRequestStep();
            if (!dialog.open) {
                dialog.showModal();
            }
            emailInput.focus();
        });
    });

    form.querySelector("[data-password-reset-close]").addEventListener("click", function () {
        dialog.close();
    });

    form.querySelector("[data-password-reset-back]").addEventListener("click", showRequestStep);

    sendButton.addEventListener("click", async function () {
        if (!emailInput.reportValidity()) {
            return;
        }
        sendButton.disabled = true;
        showMessage("Sending recovery PIN...", false);
        try {
            const result = await submitRecovery("send_pin");
            showVerifyStep();
            showMessage(result.message, false);
            pinInput.focus();
        } catch (error) {
            showMessage(error.message, true);
        } finally {
            sendButton.disabled = false;
        }
    });

    form.addEventListener("submit", async function (event) {
        event.preventDefault();
        if (!form.reportValidity()) {
            return;
        }
        const submitButton = form.querySelector('[type="submit"]');
        submitButton.disabled = true;
        showMessage("Resetting your password...", false);
        try {
            const result = await submitRecovery("reset_password");
            showMessage(result.message, false);
            window.setTimeout(function () {
                dialog.close();
                form.reset();
                showRequestStep();
            }, 1800);
        } catch (error) {
            showMessage(error.message, true);
        } finally {
            submitButton.disabled = false;
        }
    });

    dialog.addEventListener("click", function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});
