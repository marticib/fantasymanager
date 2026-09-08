const base = {
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.8,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
}

function Svg({ children, className }) {
  return (
    <svg viewBox="0 0 24 24" className={className} {...base}>
      {children}
    </svg>
  )
}

export const LogoIcon = (p) => (
  <Svg {...p}>
    <path d="M3 12h4l2-7 4 14 2-7h6" />
  </Svg>
)

export const BoltIcon = (p) => (
  <Svg {...p}>
    <path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z" />
  </Svg>
)

export const UsersIcon = (p) => (
  <Svg {...p}>
    <circle cx="9" cy="8" r="3.2" />
    <path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6" />
    <path d="M16 5.2c1.6.3 2.8 1.7 2.8 3.3s-1.2 3-2.8 3.3" />
    <path d="M15.5 14.2c2.9.5 4.5 2.6 4.5 5.8" />
  </Svg>
)

export const ShopIcon = (p) => (
  <Svg {...p}>
    <path d="M4 10 5.2 4h13.6L20 10" />
    <path d="M4 10h16v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9Z" />
    <path d="M9 20v-5a3 3 0 0 1 6 0v5" />
  </Svg>
)

export const GridIcon = (p) => (
  <Svg {...p}>
    <rect x="3.5" y="3.5" width="7" height="7" rx="1.2" />
    <rect x="13.5" y="3.5" width="7" height="7" rx="1.2" />
    <rect x="3.5" y="13.5" width="7" height="7" rx="1.2" />
    <rect x="13.5" y="13.5" width="7" height="7" rx="1.2" />
  </Svg>
)

export const ShieldIcon = (p) => (
  <Svg {...p}>
    <path d="M12 3 5 5.5V11c0 4.8 3 8.3 7 9.5 4-1.2 7-4.7 7-9.5V5.5L12 3Z" />
    <path d="M9.5 12l1.8 1.8L14.8 10" />
  </Svg>
)

export const TrendingUpIcon = (p) => (
  <Svg {...p}>
    <path d="M3 16l6-6 4 4 8-8" />
    <path d="M15 6h6v6" />
  </Svg>
)

export const TargetIcon = (p) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="8.5" />
    <circle cx="12" cy="12" r="4.5" />
    <circle cx="12" cy="12" r="0.8" fill="currentColor" />
  </Svg>
)

export const TrophyIcon = (p) => (
  <Svg {...p}>
    <path d="M7 4h10v5a5 5 0 0 1-10 0V4Z" />
    <path d="M7 5H4v1a3.5 3.5 0 0 0 3.5 3.5" />
    <path d="M17 5h3v1A3.5 3.5 0 0 1 16.5 9.5" />
    <path d="M10 15.5v2.2M14 15.5v2.2" />
    <path d="M8.5 20.5h7" />
    <path d="M10 17.7h4v1.3a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-1.3Z" />
  </Svg>
)

export const BarChartIcon = (p) => (
  <Svg {...p}>
    <path d="M4 20V10M12 20V4M20 20v-7" />
  </Svg>
)

export const GearIcon = (p) => (
  <Svg {...p}>
    <circle cx="12" cy="12" r="3" />
    <path d="M12 3v2.2M12 18.8V21M21 12h-2.2M5.2 12H3M18.4 5.6l-1.6 1.6M7.2 16.8l-1.6 1.6M18.4 18.4l-1.6-1.6M7.2 7.2 5.6 5.6" />
  </Svg>
)

export const RefreshIcon = (p) => (
  <Svg {...p}>
    <path d="M4 12a8 8 0 0 1 14-5.3L20 8" />
    <path d="M20 4v4h-4" />
    <path d="M20 12a8 8 0 0 1-14 5.3L4 16" />
    <path d="M4 20v-4h4" />
  </Svg>
)

export const BellIcon = (p) => (
  <Svg {...p}>
    <path d="M6 10a6 6 0 0 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 14 6 10Z" />
    <path d="M10 19a2 2 0 0 0 4 0" />
  </Svg>
)

export const CalendarIcon = (p) => (
  <Svg {...p}>
    <rect x="3.5" y="4.5" width="17" height="16" rx="2" />
    <path d="M3.5 9.5h17M8 3v3M16 3v3" />
  </Svg>
)

export const WalletIcon = (p) => (
  <Svg {...p}>
    <rect x="3" y="6" width="18" height="13" rx="2" />
    <path d="M3 10h18" />
    <circle cx="16.5" cy="14" r="1" fill="currentColor" stroke="none" />
  </Svg>
)

export const LinkIcon = (p) => (
  <Svg {...p}>
    <path d="M9.5 14.5 14.5 9.5" />
    <path d="M11 6.5l1.4-1.4a3.5 3.5 0 0 1 5 5L16 11.5" />
    <path d="M13 17.5l-1.4 1.4a3.5 3.5 0 0 1-5-5L8 12.5" />
  </Svg>
)

export const SignalIcon = (p) => (
  <Svg {...p}>
    <path d="M4 18v-3M9.5 18v-6M15 18V9M20 18V5" />
  </Svg>
)

export const WarningIcon = (p) => (
  <Svg {...p}>
    <path d="M12 3.5 2.5 20h19L12 3.5Z" />
    <path d="M12 10v4.2" />
    <circle cx="12" cy="17.3" r="0.9" fill="currentColor" stroke="none" />
  </Svg>
)

export const CheckIcon = (p) => (
  <Svg {...p}>
    <path d="M5 12.5 9.5 17 19 7.5" />
  </Svg>
)

export const ChevronDownIcon = (p) => (
  <Svg {...p}>
    <path d="M6 9l6 6 6-6" />
  </Svg>
)

export const ArrowRightIcon = (p) => (
  <Svg {...p}>
    <path d="M4 12h15M13 6l6 6-6 6" />
  </Svg>
)

export const SearchIcon = (p) => (
  <Svg {...p}>
    <circle cx="11" cy="11" r="7" />
    <path d="M21 21l-4.3-4.3" />
  </Svg>
)
