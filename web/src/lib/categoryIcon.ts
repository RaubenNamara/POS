// Category icon by name (categories are created by the shop, so match on keywords).
const ICONS: [RegExp, string][] = [
  [/phone|tablet|mobile/i, 'phone'], [/comput|laptop|pc/i, 'laptop'], [/electron|tv|audio|power|solar/i, 'tv'],
  [/applian|kitchen|fridge|cook/i, 'appliance'], [/home|furnit|decor|house/i, 'home'], [/fashion|cloth|shoe|wear/i, 'shirt'],
  [/health|beauty|pharm|cosmet/i, 'health'], [/super|grocer|food|drink|bever|household/i, 'basket'], [/game|gaming|toy/i, 'gamepad'],
  [/package|bundle/i, 'layers'],
]

export const iconFor = (name: string) => ICONS.find(([re]) => re.test(name))?.[1] ?? 'tag'
