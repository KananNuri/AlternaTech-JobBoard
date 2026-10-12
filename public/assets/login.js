"use strict";

const loginForm = document.querySelector("#login-form");
const loginStatus = document.querySelector("#login-status");
const passwordAccount = document.querySelector("#password-account");

const recruiterArea = document.querySelector("#recruiter-area");
const recruiterStatus = document.querySelector("#recruiter-status");
const recruiterCompanies = document.querySelector("#recruiter-companies");

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

async function loadRecruiterCompanies() {
  recruiterArea.hidden = false;
  recruiterStatus.textContent = "Loading your companies...";
  recruiterCompanies.replaceChildren();

  try {
    const response = await fetch("/api/companies/mine", {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    });

    if (!response.ok) {
      throw new Error("Unable to load recruiter companies.");
    }

    const companies = await response.json();

    if (companies.length === 0) {
      recruiterStatus.textContent =
        "You are not registered as a recruiter for any company.";
      return;
    }

    recruiterStatus.textContent = "Your recruiter companies:";

    for (const company of companies) {
      const section = document.createElement("section");
      section.className = "recruiter-company";

      const title = document.createElement("h3");
      title.textContent = company.name;

      const adsContainer = document.createElement("div");
      adsContainer.className = "recruiter-ads";
      adsContainer.textContent = "Loading advertisements...";

      section.append(title, adsContainer);
      recruiterCompanies.append(section);

      try {
        const adsResponse = await fetch("/api/companies/mine/ads", {
          headers: { Accept: "application/json" },
          credentials: "same-origin"
        });

        if (!adsResponse.ok) {
          throw new Error("Unable to load advertisements.");
        }

        const ads = await adsResponse.json();
        const companyAds = ads.filter(
          (ad) => Number(ad.company_id) === Number(company.id)
        );

        adsContainer.replaceChildren();

        if (companyAds.length === 0) {
          adsContainer.textContent = "No advertisements published yet.";
          continue;
        }

        for (const ad of companyAds) {
          const article = document.createElement("article");
          article.className = "recruiter-ad";

          const adTitle = document.createElement("h4");
          adTitle.textContent = ad.title;

          const location = document.createElement("p");
          location.textContent = ad.location;

          const category = document.createElement("p");
          category.textContent = ad.category || "Uncategorized";

          const editButton = document.createElement("button");
          editButton.type = "button";
          editButton.textContent = "Edit";

          editButton.addEventListener("click", () => {
            openEditForm(ad, article);
          });

          article.append(adTitle, location, category, editButton);
          adsContainer.append(article);
        }
      } catch {
        adsContainer.textContent =
          "Unable to load advertisements. Please try again.";
      }
    }
  } catch {
    recruiterStatus.textContent =
      "Unable to load your companies. Please try again.";
  }
}

function showSignedIn(user) {
  recruiterArea.hidden = true;
  recruiterCompanies.replaceChildren();

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

  loadRecruiterCompanies();
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();

  const submitButton = loginForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  loginStatus.textContent = "Signing in...";

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