(function (): void {
  "use strict";

  type StatusItem = {
    status?: string;
  };

  type StatusSummary = {
    total: number;
    ok: number;
    attention: number;
  };

  type AdminFrontendApi = {
    version: string;
    source: string;
    core: string;
    operationHistorySummary: (items: StatusItem[]) => StatusSummary;
    updateHistorySummary: (items: StatusItem[]) => StatusSummary;
    conservationReportSummary: (items: StatusItem[]) => StatusSummary;
  };

  var root = document.documentElement;
  root.setAttribute("data-admin-frontend", "ready");
  root.setAttribute("data-admin-frontend-version", "v0.24");
  root.setAttribute("data-admin-frontend-source", "AdminFrontend/admin-frontend.ts");
  root.setAttribute("data-admin-frontend-core", "RepositoryCMS/Core/admin-frontend.js");
  root.setAttribute("data-admin-frontend-integrity", "source-core-aligned");

  function updateInputState(input: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement): void {
    var value = typeof input.value === "string" ? input.value.trim() : "";
    input.setAttribute("data-admin-input-state", value === "" ? "empty" : "filled");
    if (input.hasAttribute("required")) {
      input.setAttribute("aria-invalid", value === "" ? "true" : "false");
    }
  }

  function markFormState(form: HTMLFormElement, state: string): void {
    form.setAttribute("data-admin-form-state", state);
  }

  function enhanceForm(form: HTMLFormElement): void {
    if (form.getAttribute("data-admin-frontend-enhanced") === "true") {
      return;
    }
    form.setAttribute("data-admin-frontend-enhanced", "true");
    markFormState(form, "pristine");
    form.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>("input, textarea, select").forEach(function (input) {
      updateInputState(input);
      input.addEventListener("input", function () {
        updateInputState(input);
        markFormState(form, "changed");
      });
      input.addEventListener("change", function () {
        updateInputState(input);
        markFormState(form, "changed");
      });
    });
    form.addEventListener("submit", function () {
      markFormState(form, "submitting");
      var submitters = form.querySelectorAll<HTMLButtonElement | HTMLInputElement>('button[type="submit"], input[type="submit"]');
      submitters.forEach(function (submitter) {
        submitter.setAttribute("aria-busy", "true");
        submitter.setAttribute("data-admin-submit-state", "submitting");
      });
    });
  }

  function enhanceDisplayToggles(): void {
    document.querySelectorAll<HTMLElement>("[data-admin-toggle-target]").forEach(function (toggle) {
      if (toggle.getAttribute("data-admin-display-toggle") === "ready") {
        return;
      }
      var selector = toggle.getAttribute("data-admin-toggle-target");
      var target = selector ? document.querySelector<HTMLElement>(selector) : null;
      if (!target) {
        return;
      }
      toggle.setAttribute("data-admin-display-toggle", "ready");
      toggle.setAttribute("aria-expanded", target.hidden ? "false" : "true");
      toggle.addEventListener("click", function () {
        target.hidden = !target.hidden;
        toggle.setAttribute("aria-expanded", target.hidden ? "false" : "true");
      });
    });
  }

  function enhanceTables(): void {
    document.querySelectorAll<HTMLTableElement>("table.list").forEach(function (table) {
      table.setAttribute("data-admin-frontend-table", "enhanced");
      table.querySelectorAll<HTMLTableRowElement>("tbody tr").forEach(function (row, index) {
        row.setAttribute("data-admin-row-index", String(index + 1));
      });
    });
  }

  function summarizeStatus(items: StatusItem[]): StatusSummary {
    var list = Array.isArray(items) ? items : [];
    return {
      total: list.length,
      ok: list.filter(function (item) {
        return item && item.status === "ok";
      }).length,
      attention: list.filter(function (item) {
        return item && item.status !== "ok";
      }).length
    };
  }

  function operationHistorySummary(items: StatusItem[]): StatusSummary {
    return summarizeStatus(items);
  }

  function updateHistorySummary(items: StatusItem[]): StatusSummary {
    return summarizeStatus(items);
  }

  function conservationReportSummary(items: StatusItem[]): StatusSummary {
    return summarizeStatus(items);
  }

  function boot(): void {
    document.querySelectorAll<HTMLFormElement>("form").forEach(enhanceForm);
    enhanceDisplayToggles();
    enhanceTables();
  }

  (window as Window & { RepositoryCmsAdminFrontend?: AdminFrontendApi }).RepositoryCmsAdminFrontend = {
    version: "v0.24",
    source: "AdminFrontend/admin-frontend.ts",
    core: "RepositoryCMS/Core/admin-frontend.js",
    operationHistorySummary: operationHistorySummary,
    updateHistorySummary: updateHistorySummary,
    conservationReportSummary: conservationReportSummary
  };
  root.setAttribute("data-admin-operation-history-helper", "ready");
  root.setAttribute("data-admin-update-history-helper", "ready");
  root.setAttribute("data-admin-conservation-report-helper", "ready");

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
    return;
  }
  boot();
}());
