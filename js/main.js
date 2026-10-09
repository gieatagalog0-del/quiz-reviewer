/* ==========================================================
   main.js - homepage and navigation helpers
   - Fills in question counts from js/questions.js
   - Shows this session's last/best score on the subject cards
   - Lists the quizzes shared with the class (from the MySQL server)
   - Highlights the active navigation link
   ========================================================== */
(function () {
  "use strict";

  var data = window.QUIZ_DATA || {};

  // Question counts (cards on the home page and the stats in the hero)
  var totalAll = 0;
  Object.keys(data).forEach(function (key) {
    var n = data[key].questions.length;
    totalAll += n;
    document.querySelectorAll("[data-count-for='" + key + "']").forEach(function (el) {
      el.textContent = n + " practice questions";
    });
  });
  var totalEl = document.getElementById("total-all-questions");
  if (totalEl) totalEl.textContent = String(totalAll);

  // Session scores (kept only while this browser tab session lasts)
  var scores = {};
  try { scores = JSON.parse(sessionStorage.getItem("cqr-scores") || "{}"); } catch (e) { scores = {}; }
  Object.keys(scores).forEach(function (key) {
    document.querySelectorAll("[data-score-for='" + key + "']").forEach(function (el) {
      var s = scores[key];
      el.textContent = "Last score: " + s.last + "%  |  Best: " + s.best + "%";
    });
  });

  // Quizzes shared with the class (needs a login)
  var customList = document.getElementById("custom-list");
  var empty = document.getElementById("custom-empty");
  if (customList && window.Api) {
    window.Api.me().then(function (m) {
      if (!m.user) {
        if (empty) empty.innerHTML = 'Log in to see the quizzes shared by your class. <a href="login.html">Log in</a> or <a href="register.html">register</a>.';
        return;
      }
      window.Api.request("GET", "/api/quizzes").then(function (r) {
        var mine = r.ok ? r.data : [];
        if (empty) {
          empty.textContent = "No quizzes have been shared yet.";
          empty.classList.toggle("hidden", mine.length > 0);
        }
        mine.forEach(function (quiz) {
          var row = document.createElement("article");
          row.className = "saved-item";
          var info = document.createElement("div");
          var h = document.createElement("h3");
          h.textContent = quiz.title;
          var meta = document.createElement("p");
          meta.className = "meta";
          var sc = scores["custom-" + quiz.id];
          meta.textContent = quiz.count + " questions \u00B7 shared by " + quiz.by +
            (sc ? " \u00B7 Last score: " + sc.last + "% \u00B7 Best: " + sc.best + "%" : "");
          info.appendChild(h);
          info.appendChild(meta);
          var go = document.createElement("a");
          go.className = "btn btn-primary";
          go.href = "custom-quiz.html?id=" + encodeURIComponent(quiz.id);
          go.textContent = "Take Quiz";
          row.appendChild(info);
          row.appendChild(go);
          customList.appendChild(row);
        });
      });
    });
  }

  // Active navigation highlight on the homepage (Home / Subjects / About)
  var links = document.querySelectorAll(".nav-links a[href^='#']");
  if (links.length && "IntersectionObserver" in window) {
    var map = {};
    links.forEach(function (a) { map[a.getAttribute("href").slice(1)] = a; });
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting && map[entry.target.id]) {
          links.forEach(function (l) { l.classList.remove("active"); });
          map[entry.target.id].classList.add("active");
        }
      });
    }, { rootMargin: "-40% 0px -55% 0px" });
    Object.keys(map).forEach(function (id) {
      var sec = document.getElementById(id);
      if (sec) observer.observe(sec);
    });
  }

  // Footer year
  var y = document.getElementById("year");
  if (y) y.textContent = String(new Date().getFullYear());
})();
