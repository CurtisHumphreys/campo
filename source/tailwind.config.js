/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './intranet.html', './src/**/*.{vue,js}'],
  theme: {
    extend: {
      colors: {
        ember: {
          50:  '#fefce8',
          100: '#fef9c3',
          200: '#fde68a',
          300: '#fcd34d',
          400: '#fbbf24',
          500: '#f59e0b',
          600: '#d97706',
          700: '#b45309',
          800: '#92400e',
          900: '#78350f',
        },
        sage: {
          300: '#a3c99a',
          400: '#86b87a',
          500: '#6b9e6e',
          600: '#4a7c59',
          700: '#2d5a3d',
        },
        surface: {
          900: '#0c0a07',
          800: '#111009',
          700: '#1a1713',
          600: '#252219',
          500: '#332f24',
          400: '#3d3828',
          300: '#4a4433',
        },
        ink: {
          100: '#f5f0e8',
          200: '#e8e0d0',
          300: '#d4c9b0',
          400: '#b8a888',
          500: '#9c8f70',
          600: '#7a7060',
        }
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
        display: ['Inter', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        'xl': '1rem',
        '2xl': '1.25rem',
        '3xl': '1.5rem',
      },
      boxShadow: {
        'ember': '0 0 20px rgba(245, 158, 11, 0.15)',
        'card': '0 2px 8px rgba(0,0,0,0.4)',
        'modal': '0 8px 32px rgba(0,0,0,0.6)',
      },
      animation: {
        'fade-in': 'fadeIn 0.2s ease-out',
        'slide-up': 'slideUp 0.3s ease-out',
        'pulse-ember': 'pulseEmber 2s ease-in-out infinite',
      },
      keyframes: {
        fadeIn: { from: { opacity: 0 }, to: { opacity: 1 } },
        slideUp: { from: { transform: 'translateY(16px)', opacity: 0 }, to: { transform: 'translateY(0)', opacity: 1 } },
        pulseEmber: { '0%,100%': { opacity: 1 }, '50%': { opacity: 0.5 } },
      }
    }
  },
  plugins: []
}
