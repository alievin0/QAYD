/**
 * `@qayd/ui` — the QAYD design-system primitives (shadcn/ui + Radix on the brass tokens) the Sprint-1
 * shell and auth screens need. Accessible (Radix), RTL-aware (logical properties), light/dark via the
 * `.dark` class. Import the token stylesheet once: `import "@qayd/ui/styles.css";`.
 */

// Utilities
export { cn } from "./lib/cn.js";

// Theme
export {
  ThemeProvider,
  ThemeToggle,
  useTheme,
  type ResolvedTheme,
  type Theme,
  type ThemeProviderProps,
} from "./lib/theme.js";

// Primitives
export { Button, buttonVariants, type ButtonProps } from "./components/button.js";
export { Input, type InputProps } from "./components/input.js";
export { Label } from "./components/label.js";
export {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "./components/card.js";
export {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectScrollDownButton,
  SelectScrollUpButton,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from "./components/select.js";
export {
  CheckIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  MoonIcon,
  SunIcon,
} from "./components/icons.js";
