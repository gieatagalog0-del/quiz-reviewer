/* ==========================================================
   shared-quizzes.js - used by custom-quiz.html
   Loads one shared quiz from the server (MySQL), then starts the
   normal quiz engine (js/quiz.js) unchanged.
   ========================================================== */
(function () {
  "use strict";
  var id = new URLSearchParams(window.location.search).get("id");

  function boot(saved) {
    window.CustomQuizzes = { get: function () { return saved; } };
    var s = document.createElement("script");
    s.src = "js/quiz.js";
    document.body.appendChild(s);
  }

  if (!id) { boot(null); return; }
  Api.request("GET", "/api/quizzes/" + encodeURIComponent(id))
    .then(function (r) { boot(r.ok ? r.data : null); })
    .catch(function () { boot(null); });
})();
