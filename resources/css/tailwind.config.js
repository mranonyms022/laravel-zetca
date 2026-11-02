/** Dashboard stylesheet build (see resources/css/zatca.css). Paths are relative to the package root. */
module.exports = {
  darkMode: 'class',
  content: ['./resources/views/**/*.blade.php', './src/Laravel/Dashboard/**/*.php'],
  theme: { extend: {} },
  plugins: [],
};
