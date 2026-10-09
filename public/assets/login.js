
"use strict";

const loginForm = document.querySelector("#login-form");
const loginStatus = document.querySelector("#login-status");
const passwordAccount = document.querySelector("#password-account");

async function refreshPasswordSession() {
  const response = await fetch("/api/auth/session", {
    headers: { Accept: "application/json" },
    credentials: "same-origin"
  });

  if (!response.ok) {
    throw new Error("Unable to check login session.");
  }

  return response.json();
}

function showSignedIn(user) {
  passwordAccount.replaceChildren();

  const title = document.createElement("h2");
  title.textContent = `Signed in as ${user.first_name} ${user.last_name}`;

  const email = document.createElement("p");
  email.textContent = user.email;

  const logoutButton = document.createElement("button");
  logoutButton.type = "button";
  logoutButton.textContent = "Sign out";

  logoutButton.addEventListener("click", async () => {
    logoutButton.disabled = true;
    loginStatus.textContent = "";

    try {
      const session = await refreshPasswordSession();

      if (!session.csrf_token) {
        throw new Error("Missing CSRF token.");
      }

      const response = await fetch("/api/auth/logout", {
        method: "POST",
        headers: {
          Accept: "application/json",
          "X-CSRF-Token": session.csrf_token
        },
        credentials: "same-origin"
      });

      if (!response.ok) {
        throw new Error("Unable to sign out.");
      }

      window.location.reload();
    } catch {
      logoutButton.disabled = false;
      loginStatus.textContent = "Unable to sign out. Please try again.";
    }
  });

  passwordAccount.append(title, email, logoutButton);
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();

  const submitButton = loginForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  loginStatus.textContent = "Signing in…";

  try {
    const body = {
      email: loginForm.elements.email.value.trim(),
      password: loginForm.elements.password.value
    };

    const response = await fetch("/api/auth/login", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json"
      },
      credentials: "same-origin",
      body: JSON.stringify(body)
    });

    const data = await response.json();

    if (!response.ok) {
      throw new Error(data.error || "Sign-in failed.");
    }

    loginForm.reset();
    showSignedIn(data.user);
  } catch (error) {
    loginStatus.textContent =
      error.message === "Failed to fetch"
        ? "Unable to reach the server. Please try again."
        : error.message;
  } finally {
    submitButton.disabled = false;
  }
});

refreshPasswordSession()
  .then((data) => {
    if (data.user) {
      showSignedIn(data.user);
    }
  })
  .catch(() => {
    loginStatus.textContent = "Unable to check login session.";
  });
