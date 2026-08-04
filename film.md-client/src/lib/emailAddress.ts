export const POPULAR_EMAIL_DOMAINS = [
  "gmail.com",
  "googlemail.com",
  "yahoo.com",
  "outlook.com",
  "hotmail.com",
  "live.com",
  "icloud.com",
  "mail.ru",
  "inbox.ru",
  "list.ru",
  "bk.ru",
  "yandex.ru",
  "rambler.ru",
  "proton.me",
  "protonmail.com",
] as const;

const DOMAIN_CORRECTIONS: Record<string, (typeof POPULAR_EMAIL_DOMAINS)[number]> = {
  "gmal.com": "gmail.com",
  "gmial.com": "gmail.com",
  "gmai.com": "gmail.com",
  "gamil.com": "gmail.com",
  "gmaill.com": "gmail.com",
  "gmail.co": "gmail.com",
  "gmail.con": "gmail.com",
  "gmail.cm": "gmail.com",
  "gmail.om": "gmail.com",
  "gmail.comm": "gmail.com",
  "gmail.comn": "gmail.com",
  "gmail.cim": "gmail.com",
  "gmail.cpm": "gmail.com",
  gmail: "gmail.com",
  gmal: "gmail.com",
  "googlemai.com": "googlemail.com",
  "googlemail.co": "googlemail.com",
  "googlemail.con": "googlemail.com",
  "yaho.com": "yahoo.com",
  "yhoo.com": "yahoo.com",
  "yahooo.com": "yahoo.com",
  "yahho.com": "yahoo.com",
  "yaoo.com": "yahoo.com",
  "yahoo.co": "yahoo.com",
  "yahoo.con": "yahoo.com",
  "yahoo.cm": "yahoo.com",
  "outlok.com": "outlook.com",
  "outllook.com": "outlook.com",
  "outloo.com": "outlook.com",
  "outlook.co": "outlook.com",
  "outlook.con": "outlook.com",
  "outlook.cm": "outlook.com",
  "hotmal.com": "hotmail.com",
  "hotmial.com": "hotmail.com",
  "hotmai.com": "hotmail.com",
  "hotmaill.com": "hotmail.com",
  "hotmail.co": "hotmail.com",
  "hotmail.con": "hotmail.com",
  "hotmail.cm": "hotmail.com",
  "live.co": "live.com",
  "live.con": "live.com",
  "iclud.com": "icloud.com",
  "iclou.com": "icloud.com",
  "icloud.co": "icloud.com",
  "icloud.con": "icloud.com",
  "icloud.cm": "icloud.com",
  "mai.ru": "mail.ru",
  "mal.ru": "mail.ru",
  "mail.ry": "mail.ru",
  mailru: "mail.ru",
  "inbox.ry": "inbox.ru",
  inboxru: "inbox.ru",
  "list.ry": "list.ru",
  listru: "list.ru",
  "bk.ry": "bk.ru",
  bkru: "bk.ru",
  "yandx.ru": "yandex.ru",
  "yanex.ru": "yandex.ru",
  "yandex.ry": "yandex.ru",
  yandexru: "yandex.ru",
  "ramber.ru": "rambler.ru",
  "rambler.ry": "rambler.ru",
  ramblerru: "rambler.ru",
  "protom.me": "proton.me",
  "proton.ne": "proton.me",
  "protonmal.com": "protonmail.com",
  "protonmai.com": "protonmail.com",
  "protonmail.co": "protonmail.com",
  "protonmail.con": "protonmail.com",
};

const PROVIDER_CORRECTIONS: Record<string, string> = {
  gmal: "gmail",
  gmial: "gmail",
  gmai: "gmail",
  gamil: "gmail",
  gmaill: "gmail",
  googlemai: "googlemail",
  yaho: "yahoo",
  yhoo: "yahoo",
  yahooo: "yahoo",
  yahho: "yahoo",
  yaoo: "yahoo",
  outlok: "outlook",
  outllook: "outlook",
  outloo: "outlook",
  hotmal: "hotmail",
  hotmial: "hotmail",
  hotmai: "hotmail",
  hotmaill: "hotmail",
  iclud: "icloud",
  iclou: "icloud",
  mai: "mail",
  mal: "mail",
  yandx: "yandex",
  yanex: "yandex",
  ramber: "rambler",
  protom: "proton",
  protonmal: "protonmail",
  protonmai: "protonmail",
};

const TLD_CORRECTIONS: Record<string, string> = {
  con: "com",
  co: "com",
  cm: "com",
  om: "com",
  comm: "com",
  comn: "com",
  cim: "com",
  cpm: "com",
  ry: "ru",
};

const popularDomainSet = new Set<string>(POPULAR_EMAIL_DOMAINS);

function matchPopularDomain(domain: string): string {
  const directCorrection = DOMAIN_CORRECTIONS[domain];
  if (directCorrection) return directCorrection;

  const parts = domain.split(".");
  if (parts.length !== 2) return domain;

  const provider = PROVIDER_CORRECTIONS[parts[0]] ?? parts[0];
  const tld = TLD_CORRECTIONS[parts[1]] ?? parts[1];
  const candidate = `${provider}.${tld}`;

  return popularDomainSet.has(candidate) ? candidate : domain;
}

export interface EmailAddressCorrection {
  email: string;
  changed: boolean;
  originalDomain?: string;
  correctedDomain?: string;
}

export function correctPopularEmailAddress(value: string): EmailAddressCorrection {
  const trimmed = value.trim();
  const atIndex = trimmed.lastIndexOf("@");

  if (atIndex <= 0 || atIndex === trimmed.length - 1) {
    return { email: trimmed, changed: false };
  }

  const localPart = trimmed.slice(0, atIndex);
  const originalDomain = trimmed.slice(atIndex + 1).toLowerCase();
  const correctedDomain = matchPopularDomain(originalDomain);

  return {
    email: `${localPart}@${correctedDomain}`,
    changed: correctedDomain !== originalDomain,
    originalDomain,
    correctedDomain,
  };
}
