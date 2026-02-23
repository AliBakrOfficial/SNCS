/* eslint-env node */

const { configure } = require("quasar/wrappers");

module.exports = configure(function (/* ctx */) {
  return {
    eslint: {
      warnings: true,
      errors: true,
    },

    boot: ["axios", "i18n"],

    css: ["app.css"],

    extras: ["material-icons", "roboto-font"],

    build: {
      target: {
        browser: ["es2019", "edge88", "firefox78", "chrome87", "safari13.1"],
      },
      vueRouterMode: "history",
    },

    devServer: {
      open: true,
    },

    framework: {
      config: {
        dark: "auto",
      },
      plugins: [
        "Notify",
        "Dialog",
        "Loading",
        "LocalStorage",
        "AppFullscreen",
        "Meta",
      ],
      lang: "ar",
    },

    animations: [],

    pwa: {
      workboxMode: "GenerateSW",
      injectPwaMetaTags: true,
      swFilename: "sw.js",
      manifestFilename: "manifest.json",
      useCredentialsForManifestTag: false,
      extendManifestJson(json) {
        json.name = "SNCS نظام نداء التمريض";
        json.short_name = "SNCS";
        json.description = "Smart Nurse Calling System";
        json.display = "standalone";
        json.theme_color = "#1B4F8A";
        json.background_color = "#ffffff";
        json.dir = "rtl";
        json.lang = "ar";
      },
    },
  };
});
