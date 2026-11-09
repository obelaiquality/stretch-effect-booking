import type { SVGProps } from 'react';

export type IconName =
  | 'calendar' | 'clock' | 'user' | 'users' | 'plus' | 'minus' | 'check' | 'x' | 'chevron-left' | 'chevron-right'
  | 'arrow-right' | 'lock' | 'shield' | 'chat' | 'refresh' | 'logout' | 'google' | 'whatsapp' | 'menu' | 'alert' | 'card' | 'eye' | 'eye-off' | 'download';

const P: Record<IconName, JSX.Element> = {
  calendar: <><rect x="3.5" y="5" width="17" height="15.5" /><path d="M3.5 10h17M8 3v4M16 3v4" /></>,
  clock: <><circle cx="12" cy="12" r="8.5" /><path d="M12 7v5.5l3.5 2" /></>,
  user: <><circle cx="12" cy="8.5" r="3.8" /><path d="M4.5 20.5c.8-4 3.8-6 7.5-6s6.7 2 7.5 6" /></>,
  users: <><circle cx="9" cy="8.5" r="3.4" /><path d="M2.5 20c.7-3.6 3.2-5.4 6.5-5.4s5.8 1.8 6.5 5.4" /><path d="M15.5 5.4a3.4 3.4 0 010 6.2M17.5 14.9c2.2.5 3.7 2.2 4.2 5.1" /></>,
  plus: <path d="M12 5v14M5 12h14" />,
  minus: <path d="M5 12h14" />,
  check: <path d="M4.5 12.5l5 5 10-11" />,
  x: <path d="M5.5 5.5l13 13M18.5 5.5l-13 13" />,
  'chevron-left': <path d="M15 5l-7 7 7 7" />,
  'chevron-right': <path d="M9 5l7 7-7 7" />,
  'arrow-right': <path d="M4 12h15M13 6l6 6-6 6" />,
  lock: <><rect x="5" y="10.5" width="14" height="10" /><path d="M8 10.5V7.5a4 4 0 018 0v3" /></>,
  shield: <><path d="M12 3l8 3v6c0 4.6-3.2 7.8-8 9-4.8-1.2-8-4.4-8-9V6l8-3z" /><path d="M8.5 12l2.5 2.5 4.5-5" /></>,
  chat: <path d="M4 5h16v11H9.5L5 20v-4H4V5z" />,
  refresh: <><path d="M20 12a8 8 0 01-14 5.3M4 12a8 8 0 0114-5.3" /><path d="M18.5 3v4h-4M5.5 21v-4h4" /></>,
  logout: <><path d="M10 4H4.5v16H10" /><path d="M9 12h11M16 8l4 4-4 4" /></>,
  google: <path d="M20.5 12.2c0-.6-.1-1.1-.2-1.6H12v3.1h4.8a4.1 4.1 0 01-1.8 2.7v2.2h2.9c1.7-1.6 2.6-3.9 2.6-6.4zM12 21c2.4 0 4.5-.8 5.9-2.2L15 16.6c-.8.5-1.8.9-3 .9-2.3 0-4.3-1.6-5-3.700H4v2.300A9 9 0 0012 21zM7 13.800a5.400 5.400 0 010-3.600V7.900H4a9 9 0 000 8.200L7 13.800zM12 6.600c1.300 0 2.500.5 3.400 1.300l2.600-2.600A9 9 0 004 7.900l3 2.300c.7-2.100 2.700-3.600 5-3.600z" />,
  whatsapp: <><path d="M4 20l1.2-4.200A8.200 8.200 0 1112 20.200a8.200 8.200 0 01-3.800-.9L4 20z" /><path d="M9 8.500c.2 2.800 2.700 5.500 6 6.500l1.200-1.500-2-1-1 .7c-.8-.4-1.600-1.200-2-2l.7-1-1-2L9 8.500z" /></>,
  menu: <path d="M4 7h16M4 12h16M4 17h16" />,
  card: <><rect x="3" y="5.5" width="18" height="13" rx="1" /><path d="M3 10h18M6.5 15h4" /></>,
  eye: <><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" /><circle cx="12" cy="12" r="3" /></>,
  'eye-off': <><path d="M4 4l16 16M9.9 5.8A9 9 0 0112 5.5c6 0 9.500 6.500 9.500 6.500a15 15 0 01-3 3.800M6.300 7.500A15 15 0 002.500 12S6 18.500 12 18.500c1.600 0 3-.4 4.200-1" /><path d="M9.900 9.900a3 3 0 004.200 4.200" /></>,
  download: <path d="M12 4v11M7 10.500l5 5 5-5M5 20h14" />,
  alert: <><path d="M12 3.500l9.500 16.500h-19L12 3.500z" /><path d="M12 10v4.500M12 17.200v.3" /></>,
};

interface Props extends Omit<SVGProps<SVGSVGElement>, 'name'> {
  name: IconName;
  size?: number;
}

export default function Icon({ name, size = 20, ...rest }: Props) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      strokeLinecap="square"
      strokeLinejoin="miter"
      aria-hidden={rest['aria-label'] ? undefined : true}
      focusable="false"
      {...rest}
    >
      {P[name]}
    </svg>
  );
}
