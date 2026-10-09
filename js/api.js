/* ==========================================================
   api.js - talks to the PHP backend (api/index.php) and fills the login area
   of the navigation bar on every page that includes it.
   ========================================================== */
(function () {
  "use strict";
  var mePromise = null;

  // Pages call "/api/me", "/api/quizzes/5" ...; the PHP backend lives in api/index.php.
  // The address is RELATIVE, so it works wherever the folder sits (for example
  // http://localhost/college-quiz-reviewer/ in XAMPP).
  function endpoint(url) { return url.replace(/^\/api\//, "api/index.php/"); }

  // opts: { json: {...} } or { raw: File/Blob, headers: {...} }
  function request(method, url, opts) {
    opts = opts || {};
    url = endpoint(url);
    var headers = { "X-Requested-With": "quiz" };
    var body;
    if (opts.json !== undefined) { headers["Content-Type"] = "application/json"; body = JSON.stringify(opts.json); }
    else if (opts.raw !== undefined) { headers["Content-Type"] = "application/octet-stream"; body = opts.raw; }
    if (opts.headers) Object.keys(opts.headers).forEach(function (k) { headers[k] = opts.headers[k]; });
    return fetch(url, { method: method, headers: headers, body: body, credentials: "same-origin" })
      .then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          return { ok: res.ok, status: res.status, data: data };
        });
      });
  }

  // Who is logged in? (cached for the page). Resolves { user: {...} | null, canUpload: bool }
  function me(force) {
    if (!mePromise || force) {
      mePromise = request("GET", "/api/me")
        .then(function (r) { return r.ok ? r.data : { user: null, canUpload: false }; })
        .catch(function () { return { user: null, canUpload: false }; });
    }
    return mePromise;
  }

  function initNav() {
    var ul = document.querySelector(".nav-links");
    if (!ul) return;
    function add(node) { var li = document.createElement("li"); li.appendChild(node); ul.appendChild(li); }
    function link(text, href) { var a = document.createElement("a"); a.href = href; a.textContent = text; return a; }
    me().then(function (m) {
      if (m.user) {
        var who = document.createElement("span");
        who.className = "nav-user";
        who.textContent = m.user.name.split(" ")[0] + (m.user.role === "admin" ? " (admin)" : "");
        add(who);
        var out = link("Log out", "#");
        out.addEventListener("click", function (e) {
          e.preventDefault();
          request("POST", "/api/logout").then(function () { window.location.href = "index.html"; });
        });
        add(out);
      } else {
        add(link("Log in", "login.html"));
        add(link("Register", "register.html"));
      }
    });
  }

  window.Api = { request: request, me: me, endpoint: endpoint };
  initNav();
})();
