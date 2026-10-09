/* ==========================================================
   upload.js - behavior of upload.html
   1. Sends the .docx to the server, which reads it and returns questions
   2. Shows a preview where missing answers can be picked
   3. Saves the quiz to MySQL so the whole class can take it
   ========================================================== */
(function () {
  "use strict";

  function $(id) { return document.getElementById(id); }
  var LETTERS = ["A", "B", "C", "D"];
  var MAX_BYTES = 5 * 1024 * 1024;
  var pending = null;

  function showStatus(kind, message, details) {
    var box = $("upload-status");
    box.className = "status " + kind;
    box.innerHTML = "";
    var p = document.createElement("p");
    p.textContent = message;
    box.appendChild(p);
    if (details && details.length) {
      var ul = document.createElement("ul");
      details.forEach(function (d) { var li = document.createElement("li"); li.textContent = d; ul.appendChild(li); });
      box.appendChild(ul);
    }
  }
  function clearStatus() { $("upload-status").className = "status hidden"; }

  function resetPreview() {
    pending = null;
    $("preview").classList.add("hidden");
    $("file-input").value = "";
  }

  // ---------- read the Word file on the server ----------
  function handleFile(file) {
    resetPreview();
    clearStatus();
    if (!file) return;
    if (!/\.docx$/i.test(file.name)) {
      showStatus("error", "Please choose a Word file that ends in .docx. In Word use File > Save As > Word Document (*.docx).");
      return;
    }
    if (file.size > MAX_BYTES) { showStatus("error", "That file is too large. The limit is 5 MB."); return; }
    showStatus("success", "Reading your Word file...");
    Api.request("POST", "/api/quizzes/parse", { raw: file, headers: { "X-Filename": encodeURIComponent(file.name) } })
      .then(function (r) {
        if (!r.ok) { showStatus("error", r.data.error || "The file could not be read."); return; }
        pending = r.data;
        if (!pending.questions.length) {
          showStatus("error", "No usable questions were found.", pending.warnings);
          pending = null;
          return;
        }
        showPreview();
        if (pending.warnings.length) showStatus("warn", "The file was read, but please check these items:", pending.warnings);
        else showStatus("success", "File read: " + pending.questions.length + " question" + (pending.questions.length === 1 ? "" : "s") + " found.");
      })
      .catch(function () { showStatus("error", "Could not reach the server. Start Apache and MySQL in XAMPP and open the site through http://localhost/..., not by double-clicking the file."); });
  }

  // ---------- preview ----------
  function showPreview() {
    $("quiz-title").value = pending.title;
    var list = $("preview-list");
    list.innerHTML = "";
    pending.questions.forEach(function (q, i) {
      var item = document.createElement("article");
      item.className = "review-item " + (q.answer >= 0 ? "correct" : "unanswered");
      var qt = document.createElement("div");
      qt.className = "q";
      qt.textContent = (i + 1) + ". " + q.q;
      item.appendChild(qt);
      q.choices.forEach(function (text, k) {
        var label = document.createElement("label");
        label.className = "pv-choice";
        var radio = document.createElement("input");
        radio.type = "radio";
        radio.name = "pv-" + i;
        radio.checked = q.answer === k;
        radio.addEventListener("change", function () {
          q.answer = k;
          item.className = "review-item correct";
          updateSave();
        });
        var span = document.createElement("span");
        span.textContent = LETTERS[k] + ". " + text;
        label.appendChild(radio);
        label.appendChild(span);
        item.appendChild(label);
      });
      list.appendChild(item);
    });
    updateSave();
    $("preview").classList.remove("hidden");
  }

  function updateSave() {
    var missing = pending.questions.filter(function (q) { return q.answer < 0; }).length;
    $("save-btn").disabled = missing > 0;
    $("missing-note").textContent = missing
      ? missing + " question(s) still need a correct answer (marked in yellow). Choose it to continue."
      : pending.questions.length + " questions are ready. The selected choice in each question is the correct answer.";
  }

  // ---------- save ----------
  function save() {
    if (!pending) return;
    var title = $("quiz-title").value.trim();
    if (!title) { showStatus("error", "Please enter a title for your quiz."); $("quiz-title").focus(); return; }
    $("save-btn").disabled = true;
    Api.request("POST", "/api/quizzes", { json: { title: title, questions: pending.questions } })
      .then(function (r) {
        if (!r.ok) { $("save-btn").disabled = false; showStatus("error", r.data.error || "The quiz could not be saved."); return; }
        resetPreview();
        showStatus("success", "\"" + title + "\" is now shared with the class.");
        renderSaved();
      })
      .catch(function () { $("save-btn").disabled = false; showStatus("error", "Could not reach the server."); });
  }

  // ---------- shared list ----------
  function renderSaved() {
    Api.request("GET", "/api/quizzes").then(function (r) {
      var wrap = $("saved-list");
      wrap.innerHTML = "";
      if (!r.ok) { $("saved-empty").textContent = r.data.error || "Could not load the quizzes."; $("saved-empty").classList.remove("hidden"); return; }
      $("saved-empty").textContent = "No quizzes have been shared yet.";
      $("saved-empty").classList.toggle("hidden", r.data.length > 0);
      r.data.forEach(function (quiz) {
        var item = document.createElement("article");
        item.className = "saved-item";
        var info = document.createElement("div");
        var h = document.createElement("h3");
        h.textContent = quiz.title;
        var meta = document.createElement("p");
        meta.className = "meta";
        meta.textContent = quiz.count + " questions \u00B7 shared by " + quiz.by + " \u00B7 " + String(quiz.created).slice(0, 10);
        info.appendChild(h);
        info.appendChild(meta);

        var actions = document.createElement("div");
        actions.className = "btn-row";
        var take = document.createElement("a");
        take.className = "btn btn-primary";
        take.href = "custom-quiz.html?id=" + encodeURIComponent(quiz.id);
        take.textContent = "Take Quiz";
        actions.appendChild(take);

        if (quiz.canDelete) {
          var del = document.createElement("button");
          del.type = "button";
          del.className = "btn btn-danger";
          del.textContent = "Delete";
          del.addEventListener("click", function () {
            if (!window.confirm("Delete \"" + quiz.title + "\" for everyone? This cannot be undone.")) return;
            Api.request("DELETE", "/api/quizzes/" + quiz.id).then(function (d) {
              if (!d.ok) showStatus("error", d.data.error || "Could not delete the quiz.");
              renderSaved();
            });
          });
          actions.appendChild(del);
        }
        item.appendChild(info);
        item.appendChild(actions);
        wrap.appendChild(item);
      });
    });
  }

  // ---------- start ----------
  Api.me().then(function (m) {
    if (!m.user) { $("gate").classList.remove("hidden"); return; }
    $("app").classList.remove("hidden");
    if (!m.canUpload) {
      $("uploader").classList.add("hidden");
      $("guide").classList.add("hidden");
      $("role-note").textContent = "Only the admin can upload quizzes right now. You can take any quiz below.";
    } else {
      $("role-note").textContent = m.user.role === "admin"
        ? "As admin you can delete any quiz."
        : "You can delete only the quizzes you uploaded.";
    }
    renderSaved();
  });

  $("file-input").addEventListener("change", function (e) { handleFile(e.target.files[0]); });
  $("save-btn").addEventListener("click", save);
  $("cancel-btn").addEventListener("click", function () { resetPreview(); clearStatus(); });

  var zone = $("drop-zone");
  ["dragenter", "dragover"].forEach(function (ev) {
    zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add("dragging"); });
  });
  ["dragleave", "drop"].forEach(function (ev) {
    zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove("dragging"); });
  });
  zone.addEventListener("drop", function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
  });
})();
