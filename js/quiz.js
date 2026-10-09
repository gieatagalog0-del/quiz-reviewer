/* ==========================================================
   QUIZ ENGINE  -  js/quiz.js
   Works on every subject page. The page's <body> declares which
   subject it uses:  <body data-subject="networking">
   Everything below runs in the browser (no server required).
   ========================================================== */
(function () {
  "use strict";

  var subjectKey = document.body.getAttribute("data-subject");
  var subject = window.QUIZ_DATA && window.QUIZ_DATA[subjectKey];
  var isCustom = false;

  // Uploaded quizzes: <body data-subject="custom"> and the page URL has ?id=...
  // The quiz is loaded from the database by js/shared-quizzes.js (shared class quizzes).
  if (subjectKey === "custom") {
    var id = new URLSearchParams(window.location.search).get("id");
    var saved = id && window.CustomQuizzes ? window.CustomQuizzes.get(id) : null;
    if (saved) {
      isCustom = true;
      subjectKey = "custom-" + saved.id;
      subject = { name: saved.title, questions: saved.questions };
    }
  }

  if (!subject) {
    var missing = document.getElementById("missing-view");
    var reviewer = document.getElementById("reviewer-view");
    if (missing && reviewer) { reviewer.classList.add("hidden"); missing.classList.remove("hidden"); }
    console.error("No question data found for subject:", subjectKey);
    return;
  }

  // Fill in the quiz title wherever the page asks for it
  Array.prototype.forEach.call(document.querySelectorAll("[data-subject-name]"), function (el) {
    el.textContent = subject.name;
  });
  if (isCustom) { document.title = subject.name + " | College Quiz Reviewer"; }

  var bank = subject.questions;          // original questions (as written in questions.js)
  var questions = bank;                  // working copy used during an attempt
  var total = bank.length;
  var LETTERS = ["A", "B", "C", "D"];

  var answers = new Array(total).fill(null); // selected index per question, or null
  var current = 0;

  // ---- element helpers ----
  function $(id) { return document.getElementById(id); }
  var views = { reviewer: $("reviewer-view"), quiz: $("quiz-view"), results: $("results-view") };

  function showView(name) {
    Object.keys(views).forEach(function (k) {
      views[k].classList.toggle("hidden", k !== name);
    });
    window.scrollTo({ top: 0 });
    var heading = views[name].querySelector("[data-focus]");
    if (heading) { heading.setAttribute("tabindex", "-1"); heading.focus({ preventScroll: true }); }
  }

  function countAnswered() {
    return answers.filter(function (a) { return a !== null; }).length;
  }

  // ---------------------------------------------------------
  // Rendering the quiz
  // ---------------------------------------------------------
  function renderQuestion() {
    var item = questions[current];
    $("q-counter").textContent = "Question " + (current + 1) + " of " + total;
    $("q-answered").textContent = countAnswered() + " of " + total + " answered";
    $("q-text").textContent = item.q;

    var bar = $("progress-bar");
    var pct = Math.round(((current + 1) / total) * 100);
    bar.style.width = pct + "%";
    $("progress").setAttribute("aria-valuenow", String(current + 1));
    $("progress").setAttribute("aria-valuemax", String(total));

    var box = $("choices");
    box.innerHTML = "";
    item.choices.forEach(function (text, i) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "choice";
      btn.setAttribute("role", "radio");
      btn.setAttribute("aria-checked", answers[current] === i ? "true" : "false");

      var letter = document.createElement("span");
      letter.className = "letter";
      letter.textContent = LETTERS[i];
      var label = document.createElement("span");
      label.textContent = text;
      btn.appendChild(letter);
      btn.appendChild(label);

      btn.addEventListener("click", function () { selectAnswer(i); });
      box.appendChild(btn);
    });

    $("prev-btn").disabled = current === 0;
    $("next-btn").disabled = current === total - 1;
    renderDots();
  }

  function renderDots() {
    var wrap = $("dots");
    wrap.innerHTML = "";
    for (var i = 0; i < total; i++) {
      (function (n) {
        var d = document.createElement("button");
        d.type = "button";
        d.className = "dot" + (answers[n] !== null ? " answered" : "") + (n === current ? " current" : "");
        d.textContent = String(n + 1);
        d.setAttribute("aria-label", "Go to question " + (n + 1) + (answers[n] !== null ? " (answered)" : " (not answered)"));
        if (n === current) d.setAttribute("aria-current", "true");
        d.addEventListener("click", function () { current = n; renderQuestion(); });
        wrap.appendChild(d);
      })(i);
    }
  }

  function selectAnswer(i) {
    answers[current] = i; // students may change their answer any time before submitting
    renderQuestion();
  }

  function goNext() { if (current < total - 1) { current++; renderQuestion(); } }
  function goPrev() { if (current > 0) { current--; renderQuestion(); } }

  // ---------------------------------------------------------
  // Start / retake
  // ---------------------------------------------------------
  // Shuffle the answer choices of every question for each attempt, so the
  // correct answer is not always in the same position. The correct answer
  // is tracked by its index after shuffling, so scoring stays accurate.
  function prepareQuestions() {
    return bank.map(function (item) {
      var order = item.choices.map(function (_, i) { return i; });
      for (var i = order.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var t = order[i]; order[i] = order[j]; order[j] = t;
      }
      return {
        q: item.q,
        choices: order.map(function (o) { return item.choices[o]; }),
        answer: order.indexOf(item.answer),
        explanation: item.explanation
      };
    });
  }

  function startQuiz() {
    questions = prepareQuestions();
    answers = new Array(total).fill(null);
    current = 0;
    showView("quiz");
    renderQuestion();
  }

  // ---------------------------------------------------------
  // Submit with confirmation
  // ---------------------------------------------------------
  function requestSubmit() {
    var unanswered = total - countAnswered();
    var warn = $("confirm-warning");
    if (unanswered > 0) {
      warn.textContent = "You still have " + unanswered + " unanswered question" + (unanswered === 1 ? "" : "s") + ". Unanswered questions are not counted as correct.";
      warn.classList.remove("hidden");
    } else {
      warn.classList.add("hidden");
    }
    var dlg = $("confirm-dialog");
    if (typeof dlg.showModal === "function") {
      dlg.showModal();
    } else if (window.confirm("Submit your quiz? You will not be able to change your answers.")) {
      submitQuiz();
    }
  }

  // ---------------------------------------------------------
  // Scoring
  // ---------------------------------------------------------
  function calculate() {
    var correct = 0, incorrect = 0, unanswered = 0;
    answers.forEach(function (a, i) {
      if (a === null) unanswered++;
      else if (a === questions[i].answer) correct++;
      else incorrect++;
    });
    // Percentage = (Correct Answers / Total Questions) x 100
    var percentage = (correct / total) * 100;
    return { correct: correct, incorrect: incorrect, unanswered: unanswered, percentage: percentage };
  }

  function performanceMessage(p) {
    if (p >= 90) return "Excellent work! You are ready for the exam.";
    if (p >= 75) return "Great job! Review the few items you missed and you will be set.";
    if (p >= 60) return "Good effort. You are passing, but a bit more review will make you confident.";
    if (p >= 40) return "Keep practicing. Re-read the reviewer, then retake the quiz.";
    return "Don't give up. Go back to the reviewer, study each topic, and try again.";
  }

  function submitQuiz() {
    var r = calculate();
    var pctText = (Math.round(r.percentage * 10) / 10).toFixed(1) + "%";

    $("res-subject").textContent = subject.name;
    $("res-total").textContent = String(total);
    $("res-correct").textContent = String(r.correct);
    $("res-incorrect").textContent = String(r.incorrect);
    $("res-unanswered").textContent = String(r.unanswered);
    $("res-score").textContent = r.correct + " / " + total;
    $("res-pct").textContent = pctText;
    $("res-msg").textContent = performanceMessage(r.percentage);
    $("res-formula").textContent = "Percentage = (" + r.correct + " ÷ " + total + ") × 100 = " + pctText;

    renderReview();
    saveSessionScore(r);
    sendToServerIfAvailable(r);
    showView("results");
  }

  function renderReview() {
    var list = $("review-list");
    list.innerHTML = "";
    questions.forEach(function (item, i) {
      var chosen = answers[i];
      var status = chosen === null ? "unanswered" : (chosen === item.answer ? "correct" : "incorrect");

      var wrap = document.createElement("article");
      wrap.className = "review-item " + status;

      var q = document.createElement("div");
      q.className = "q";
      q.textContent = (i + 1) + ". " + item.q;
      var badge = document.createElement("span");
      badge.className = "badge " + status;
      badge.textContent = status === "correct" ? "Correct" : status === "incorrect" ? "Incorrect" : "Unanswered";
      q.appendChild(badge);
      wrap.appendChild(q);

      var yours = document.createElement("p");
      yours.className = "review-line yours" + (status === "incorrect" ? " wrong" : "");
      yours.textContent = "Your answer: " + (chosen === null ? "No answer selected" : LETTERS[chosen] + ". " + item.choices[chosen]);
      wrap.appendChild(yours);

      if (status !== "correct") {
        var right = document.createElement("p");
        right.className = "review-line right";
        right.textContent = "Correct answer: " + LETTERS[item.answer] + ". " + item.choices[item.answer];
        wrap.appendChild(right);
      }

      var why = document.createElement("div");
      why.className = "explain";
      why.textContent = "Explanation: " + item.explanation;
      wrap.appendChild(why);

      list.appendChild(wrap);
    });
  }

  // ---------------------------------------------------------
  // Session storage: scores last only for this browser tab session
  // (no names, no accounts, nothing personal is stored).
  // ---------------------------------------------------------
  function saveSessionScore(r) {
    try {
      var all = JSON.parse(sessionStorage.getItem("cqr-scores") || "{}");
      var prev = all[subjectKey];
      var pct = Math.round(r.percentage * 10) / 10;
      all[subjectKey] = {
        last: pct,
        best: prev ? Math.max(prev.best, pct) : pct,
        attempts: prev ? prev.attempts + 1 : 1
      };
      sessionStorage.setItem("cqr-scores", JSON.stringify(all));
    } catch (e) { /* storage may be blocked; the quiz still works */ }
  }

  // ---------------------------------------------------------
  // OPTIONAL PHP backend (api/index.php).
  // If the page is opened through a PHP server (XAMPP), the anonymous
  // result (subject + percentage only) is saved in MySQL and the class
  // average is shown. If not, this silently does nothing.
  // ---------------------------------------------------------
  function sendToServerIfAvailable(r) {
    if (isCustom) return; // only the built-in subjects report to the optional PHP backend
    if (location.protocol !== "http:" && location.protocol !== "https:") return;
    if (typeof fetch !== "function") return;
    var body = "subject=" + encodeURIComponent(subjectKey) + "&percentage=" + encodeURIComponent(r.percentage.toFixed(1));
    var note = $("server-note");
    note.classList.add("hidden");
    fetch("api/index.php/attempts", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body
    })
      .then(function (res) { if (!res.ok) throw new Error("no server"); return res.json(); })
      .then(function (data) {
        note.textContent = "Class stats for this subject: " + data.attempts + " attempt(s) recorded, average score " + data.average + "%.";
        note.classList.remove("hidden");
      })
      .catch(function () { /* static hosting: ignore */ });
  }

  // ---------------------------------------------------------
  // Wire up buttons
  // ---------------------------------------------------------
  $("take-quiz-btn").addEventListener("click", startQuiz);
  var extraStart = document.querySelectorAll("[data-action='take-quiz']");
  Array.prototype.forEach.call(extraStart, function (b) { b.addEventListener("click", startQuiz); });

  $("prev-btn").addEventListener("click", goPrev);
  $("next-btn").addEventListener("click", goNext);
  $("submit-btn").addEventListener("click", requestSubmit);

  $("confirm-yes").addEventListener("click", function () {
    $("confirm-dialog").close();
    submitQuiz();
  });
  $("confirm-no").addEventListener("click", function () { $("confirm-dialog").close(); });

  $("retake-btn").addEventListener("click", startQuiz);
  $("back-to-reviewer-btn").addEventListener("click", function () { showView("reviewer"); });

  $("quiz-exit-btn").addEventListener("click", function () {
    if (countAnswered() === 0 || window.confirm("Leave the quiz? Your answers will be cleared.")) {
      showView("reviewer");
    }
  });

  // Keyboard shortcuts while taking the quiz: arrows to move, A-D to answer
  document.addEventListener("keydown", function (e) {
    if (views.quiz.classList.contains("hidden")) return;
    if ($("confirm-dialog").open) return;
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === "ArrowRight") { goNext(); }
    else if (e.key === "ArrowLeft") { goPrev(); }
    else {
      var idx = LETTERS.indexOf(e.key.toUpperCase());
      if (idx !== -1 && e.key.length === 1) { selectAnswer(idx); }
    }
  });

  $("total-questions").textContent = String(total);
})();
