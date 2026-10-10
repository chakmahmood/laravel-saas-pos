/**
 * Minimal inline icon set (stroke-based, 24x24). Inlined to avoid an external
 * icon dependency and keep the bundle small.
 */
export const ICONS = {
  dashboard: ['M4 4h7v7H4z', 'M13 4h7v7h-7z', 'M4 13h7v7H4z', 'M13 13h7v7h-7z'],
  box: ['M21 8l-9-5-9 5 9 5 9-5z', 'M3 8v8l9 5 9-5V8', 'M12 13v8'],
  tag: [
    'M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0l-6.6-6.6V4h9.9l6.7 6.6a2 2 0 0 1 0 2.8z',
    'M7.5 7.5h.01',
  ],
  users: [
    'M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20',
    'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
    'M22 20v-1.5a4 4 0 0 0-3-3.87',
    'M16.5 4.13A4 4 0 0 1 16.5 11.5',
  ],
  receipt: ['M6 2h12v20l-3-2-3 2-3-2-3 2V2z', 'M9 7h6', 'M9 11h6', 'M9 15h4'],
  card: [
    'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z',
    'M3 10h18',
    'M7 15h4',
  ],
  wallet: ['M3 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z', 'M3 9h18', 'M16 13h.01'],
  layers: ['M12 2l9 5-9 5-9-5 9-5z', 'M3 12l9 5 9-5', 'M3 17l9 5 9-5'],
  settings: [
    'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    'M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1',
  ],
  logout: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M16 17l5-5-5-5', 'M21 12H9'],
  menu: ['M4 6h16', 'M4 12h16', 'M4 18h16'],
  close: ['M6 6l12 12', 'M18 6L6 18'],
  chevronDown: ['M6 9l6 6 6-6'],
  chevronRight: ['M9 6l6 6-6 6'],
  store: ['M3 9l1.6-5h14.8L21 9', 'M4 9v11h16V9', 'M4 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0'],
  alertTriangle: ['M12 3l9 16H3l9-16z', 'M12 9v5', 'M12 17h.01'],
  alertCircle: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 8v5', 'M12 16h.01'],
  checkCircle: ['M22 11.1V12a10 10 0 1 1-5.9-9.1', 'M22 4l-10 10.01L9 11'],
  info: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 11v5', 'M12 8h.01'],
  refresh: ['M21 12a9 9 0 1 1-3-6.7L21 8', 'M21 3v5h-5'],
  user: ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
  sparkle: ['M12 3v6M12 15v6M3 12h6M15 12h6', 'M12 12h.01'],
  plus: ['M12 5v14', 'M5 12h14'],
}

export type IconName = keyof typeof ICONS
