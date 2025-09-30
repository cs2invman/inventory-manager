/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./templates/**/*.html.twig",
    "./assets/**/*.js",
    "./src/**/*.php"
  ],
  theme: {
    extend: {
      colors: {
        'cs2-orange': '#ff6b00',
        'cs2-blue': '#1e3a8a',
        'steam-blue': '#1b2838',
        'steam-light-blue': '#2a475e',
        'discord-blurple': '#5865f2',
        'discord-dark': '#2c2f33',
        'rarity-consumer': '#b0c3d9',
        'rarity-industrial': '#5e98d9',
        'rarity-milspec': '#4b69ff',
        'rarity-restricted': '#8847ff',
        'rarity-classified': '#d32ce6',
        'rarity-covert': '#eb4b4b',
        'rarity-contraband': '#e4ae39'
      },
      fontFamily: {
        'cs2': ['Stratum', 'Arial', 'sans-serif'],
      },
      animation: {
        'fade-in': 'fadeIn 0.5s ease-in-out',
        'slide-up': 'slideUp 0.3s ease-out',
        'pulse-slow': 'pulse 3s infinite',
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' }
        },
        slideUp: {
          '0%': { transform: 'translateY(20px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' }
        }
      },
      boxShadow: {
        'steam': '0 0 10px rgba(46, 60, 87, 0.5)',
        'cs2-glow': '0 0 20px rgba(255, 107, 0, 0.3)',
      }
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
  ],
}