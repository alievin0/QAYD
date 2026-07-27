/**
 * PostCSS pipeline for apps/web: Tailwind (reads `tailwind.config.ts`) then Autoprefixer. Next.js picks
 * this up automatically for every stylesheet in the app.
 */
const config = {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
};

export default config;
