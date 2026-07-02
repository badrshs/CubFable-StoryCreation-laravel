import { Globe } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { LANGUAGES, useI18n } from '@/i18n';

export default function LanguageSwitcher() {
    const { lang, setLang } = useI18n();

    return (
        <Select value={lang} onValueChange={setLang}>
            <SelectTrigger className="h-9 w-auto gap-2 rounded-full border-border/60 px-3 text-sm">
                <Globe className="h-4 w-4 opacity-70" />
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {LANGUAGES.map((l) => (
                    <SelectItem key={l.code} value={l.code}>
                        {l.native}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
