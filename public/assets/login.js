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
  I18N.text(recruiterStatus, "recruiter.loadingCompanies");
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
      I18N.text(recruiterStatus, "recruiter.notRecruiter");
      return;
    }

    I18N.text(recruiterStatus, "recruiter.yourCompanies");

    for (const company of companies) {
      const section = document.createElement("section");
      section.className = "recruiter-company";

      const title = document.createElement("h3");
      title.textContent = company.name;

      const adsContainer = document.createElement("div");
      adsContainer.className = "recruiter-ads";
      // Messages sit in a keyed child, so a language switch never overwrites the listed advertisements.
      adsContainer.append(I18N.text(document.createElement("span"), "recruiter.loadingAds"));

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
          adsContainer.append(I18N.text(document.createElement("span"), "recruiter.noAds"));
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
          if (ad.category) category.textContent = ad.category;
          else I18N.text(category, "recruiter.uncategorized");

          const editButton = document.createElement("button");
          editButton.type = "button";
          I18N.text(editButton, "recruiter.edit");

          editButton.addEventListener("click", () => {
            openEditForm(ad, article);
          });

          article.append(adTitle, location, category, editButton);
          adsContainer.append(article);
        }
      } catch {
        adsContainer.replaceChildren(I18N.text(document.createElement("span"), "recruiter.adsError"));
      }
    }
  } catch {
    I18N.text(recruiterStatus, "recruiter.companiesError");
  }
}

function showSignedIn(user) {
  recruiterArea.hidden = true;
  recruiterCompanies.replaceChildren();

  passwordAccount.replaceChildren();

  const title = document.createElement("h2");
  I18N.text(title, "account.signedInAs", { name: `${user.first_name} ${user.last_name}` });

  const email = document.createElement("p");
  email.textContent = user.email;

  const logoutButton = document.createElement("button");
  logoutButton.type = "button";
  I18N.text(logoutButton, "account.signOut");

  logoutButton.addEventListener("click", async () => {
    logoutButton.disabled = true;
    I18N.raw(loginStatus, "");

    try {
      const session = await refreshPasswordSession();

      if (!session.csrf_token) {
        window.location.reload(); return;
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
      I18N.text(loginStatus, "account.signOutError");
    }
  });

  passwordAccount.append(title, email, logoutButton);

  loadRecruiterCompanies();
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();

  const submitButton = loginForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  I18N.text(loginStatus, "login.signingIn");

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
      throw data.error ? new Error(data.error) : I18N.translated("login.failed");
    }

    loginForm.reset();
    showSignedIn(data.user);
  } catch (error) {
    if (error.message === "Failed to fetch") I18N.text(loginStatus, "login.unreachable");
    else I18N.error(loginStatus, error);
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
    I18N.text(loginStatus, "login.sessionError");
  });