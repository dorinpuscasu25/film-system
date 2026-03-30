
export default {
  content: [
  './index.html',
  './src/**/*.{js,ts,jsx,tsx}'
],
  theme: {
    extend: {
      colors: {
        background: '#0A0A0F',
        surface: '#14141F',
        surfaceHover: '#1E1E2E',
        accent: '#E50914',
        accentCyan: '#00B4D8',
        accentGold: '#FFB800',
        accentGreen: '#00C853',
      },
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
      }
    },
  },
  plugins: [],
}
