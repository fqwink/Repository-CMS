(function (): void {
  "use strict";

  type MetaInput = {
    title?: string;
    description?: string;
    path?: string;
  };

  type MetaSummary = {
    title: string;
    description: string;
    path: string;
    ready: boolean;
  };

  type PreviewItem = {
    path?: string;
  };

  type GenerationItem = PreviewItem & {
    generated?: boolean;
  };

  type PreviewSummary = {
    count: number;
    paths: string[];
  };

  type GenerationReportSummary = {
    total: number;
    generated: number;
    failed: number;
    paths: string[];
  };

  type StaticGeneratorApi = {
    version: string;
    source: string;
    core: string;
    compactMeta: (meta: MetaInput) => MetaSummary;
    previewSummary: (items: PreviewItem[]) => PreviewSummary;
    generationReportSummary: (items: GenerationItem[]) => GenerationReportSummary;
  };

  var root = document.documentElement;
  root.setAttribute("data-static-generator", "ready");
  root.setAttribute("data-static-generator-version", "v0.24");
  root.setAttribute("data-static-generator-source", "StaticGenerator/static-generator.ts");
  root.setAttribute("data-static-generator-core", "RepositoryCMS/Core/static-generator.js");
  root.setAttribute("data-static-generator-integrity", "source-core-aligned");

  function cleanText(value: unknown): string {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function compactMeta(meta: MetaInput): MetaSummary {
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

  function previewSummary(items: PreviewItem[]): PreviewSummary {
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

  function generationReportSummary(items: GenerationItem[]): GenerationReportSummary {
    var list = Array.isArray(items) ? items : [];
    return {
      total: list.length,
      generated: list.filter(function (item) {
        return item && item.generated === true;
      }).length,
      failed: list.filter(function (item) {
        return item && item.generated === false;
      }).length,
      paths: previewSummary(list).paths
    };
  }

  (window as Window & { RepositoryCmsStaticGenerator?: StaticGeneratorApi }).RepositoryCmsStaticGenerator = {
    version: "v0.24",
    source: "StaticGenerator/static-generator.ts",
    core: "RepositoryCMS/Core/static-generator.js",
    compactMeta: compactMeta,
    previewSummary: previewSummary,
    generationReportSummary: generationReportSummary
  };

  root.setAttribute("data-static-generator-seo-helper", "ready");
  root.setAttribute("data-static-generator-preview-helper", "ready");
  root.setAttribute("data-static-generator-report-helper", "ready");
}());
