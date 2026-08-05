(function () {
  "use strict";

  var root = document.documentElement;
  root.setAttribute("data-static-generator", "ready");
  root.setAttribute("data-static-generator-version", "v0.22");

  function cleanText(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function compactMeta(meta) {
    var title = cleanText(meta && meta.title);
    var description = cleanText(meta && meta.description);
    var path = cleanText(meta && meta.path);
    return {
      title: title,
      description: description,
      path: path,
      ready: title !== "" && description !== ""
    };
  }

  function previewSummary(items) {
    var list = Array.isArray(items) ? items : [];
    return {
      count: list.length,
      paths: list.map(function (item) {
        return cleanText(item && item.path);
      }).filter(function (path) {
        return path !== "";
      })
    };
  }

  window.RepositoryCmsStaticGenerator = {
    version: "v0.22",
    compactMeta: compactMeta,
    previewSummary: previewSummary
  };

  root.setAttribute("data-static-generator-seo-helper", "ready");
  root.setAttribute("data-static-generator-preview-helper", "ready");
}());
