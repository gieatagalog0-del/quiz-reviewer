/* ==========================================================
   auth.js - behavior of login.html, register.html, verify.html
   The page type comes from <body data-page="...">
   ========================================================== */
(function () {
  "use strict";
  function $(id) { return document.getElementById(id); }
  var page = document.body.getAttribute("data-page");

  function status(kind, message) {
    var box = $("status");
    box.className = "status " + kind;
    box.textContent = "";
    var p = document.createElement("p");
    p.textContent = message;
    box.appendChild(p);
  }
  function remember(email) { try { sessionStorage.setItem("cqr-pending-email", email); } catch (e) {} }
  function remembered() { try { return sessionStorage.getItem("cqr-pending-email") || ""; } catch (e) { return ""; } }
  function clean(v) { return v.trim().toLowerCase(); }

  // ---------------- Register ----------------
  if (page === "register") {
    $("reg-form").addEventListener("submit", function (e) {
      e.preventDefault();
      var email = clean($("email").value);
      if ($("password").value !== $("password2").value) { status("error", "The two passwords do not match."); return; }
      var btn = $("submit-btn");
      btn.disabled = true;
      status("success", "Creating your account and sending a code...");
      Api.request("POST", "/api/register", { json: { name: $("name").value, email: email, password: $("password").value } })
        .then(function (r) {
          btn.disabled = false;
          if (!r.ok) { status("error", r.data.error || "Registration failed."); return; }
          remember(email);
          window.location.href = "verify.html";
        })
        .catch(function () { btn.disabled = false; status("error", "Could not reach the server. Start Apache and MySQL in XAMPP and open the site through http://localhost/..., not by double-clicking the file."); });
    });
  }

  // ---------------- Verify (OTP) ----------------
  if (page === "verify") {
    var cooldown = 0, timer = null;
    $("email").value = remembered();

    function tick() {
      var b = $("resend-btn");
      if (cooldown > 0) { b.disabled = true; b.textContent = "Resend code (" + cooldown + "s)"; cooldown--; timer = setTimeout(tick, 1000); }
      else { b.disabled = false; b.textContent = "Resend code"; }
    }
    function resend(quiet) {
      var email = clean($("email").value);
      if (!email) { status("error", "Enter your Gmail address first."); return; }
      remember(email);
      Api.request("POST", "/api/resend", { json: { email: email } }).then(function (r) {
        if (r.ok) { if (!quiet) status("success", "If this email is waiting for verification, a new code is on its way. Check your inbox and spam folder."); }
        else if (!quiet || r.status !== 429) status("error", r.data.error || "Could not send a code.");
        clearTimeout(timer); cooldown = 60; tick();
      });
    }
    $("resend-btn").addEventListener("click", function () { resend(false); });

    $("verify-form").addEventListener("submit", function (e) {
      e.preventDefault();
      var email = clean($("email").value);
      remember(email);
      Api.request("POST", "/api/verify", { json: { email: email, code: $("code").value } }).then(function (r) {
        if (!r.ok) { status("error", r.data.error || "Verification failed."); return; }
        status("success", "Email verified! Taking you to the home page...");
        setTimeout(function () { window.location.href = "index.html"; }, 900);
      }).catch(function () { status("error", "Could not reach the server."); });
    });

    if (new URLSearchParams(window.location.search).get("resend") === "1") {
      status("success", "Your email is not verified yet. We are sending you a new code...");
      resend(true);
    } else if ($("email").value) {
      cooldown = 60; tick(); // a code was just sent when registering
    }
  }

  // ---------------- Login ----------------
  if (page === "login") {
    $("login-form").addEventListener("submit", function (e) {
      e.preventDefault();
      var email = clean($("email").value);
      var btn = $("submit-btn");
      btn.disabled = true;
      Api.request("POST", "/api/login", { json: { email: email, password: $("password").value } })
        .then(function (r) {
          btn.disabled = false;
          if (r.ok) { window.location.href = "index.html"; return; }
          if (r.status === 403 && r.data.code === "not_verified") {
            remember(email);
            window.location.href = "verify.html?resend=1";
            return;
          }
          status("error", r.data.error || "Login failed.");
        })
        .catch(function () { btn.disabled = false; status("error", "Could not reach the server. Start Apache and MySQL in XAMPP and open the site through http://localhost/..., not by double-clicking the file."); });
    });
  }
})();
