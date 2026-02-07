import { Loader2, MapPin, Navigation, Search, X } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';

import {
    type SearchResult,
    searchPlaces,
} from '@/services/geocoding';

function typeIcon(type: SearchResult['type']) {
    switch (type) {
        case 'house':
            return <MapPin className="h-4 w-4 shrink-0 text-blue-500" />;
        case 'street':
        case 'locality':
        case 'district':
            return <Navigation className="h-4 w-4 shrink-0 text-emerald-500" />;
        case 'city':
            return <Navigation className="h-4 w-4 shrink-0 text-violet-500" />;
        case 'county':
        case 'state':
            return <Navigation className="h-4 w-4 shrink-0 text-amber-500" />;
        default:
            return <MapPin className="h-4 w-4 shrink-0 text-gray-400" />;
    }
}

function typeLabel(type: SearchResult['type']): string {
    switch (type) {
        case 'house':
            return 'Address';
        case 'street':
            return 'Street';
        case 'locality':
        case 'district':
            return 'Area';
        case 'city':
            return 'City';
        case 'county':
            return 'County';
        case 'state':
            return 'Region';
        default:
            return '';
    }
}

interface MapSearchProps {
    onResultSelect: (result: SearchResult) => void;
    onClear: () => void;
}

export default function MapSearch({ onResultSelect, onClear }: MapSearchProps) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const [hasSearched, setHasSearched] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const abortRef = useRef<AbortController | null>(null);
    const containerRef = useRef<HTMLDivElement>(null);

    // Debounced search
    useEffect(() => {
        if (query.length < 3) {
            setResults([]);
            setHasSearched(false);
            setOpen(false);
            return;
        }

        const timeout = setTimeout(async () => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            setLoading(true);
            try {
                const searchResults = await searchPlaces(
                    query,
                    controller.signal,
                );
                setResults(searchResults);
                setHasSearched(true);
                setOpen(true);
                setActiveIndex(-1);
            } catch (e) {
                if (e instanceof DOMException && e.name === 'AbortError')
                    return;
                console.error('Search failed:', e);
                setResults([]);
                setHasSearched(true);
            } finally {
                setLoading(false);
            }
        }, 300);

        return () => clearTimeout(timeout);
    }, [query]);

    // Click outside closes dropdown
    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (
                containerRef.current &&
                !containerRef.current.contains(e.target as Node)
            ) {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);
        return () =>
            document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Keyboard shortcut: `/` focuses search
    useEffect(() => {
        function handler(e: KeyboardEvent) {
            if (
                e.key === '/' &&
                !isInputFocused()
            ) {
                e.preventDefault();
                inputRef.current?.focus();
            }
        }

        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, []);

    const selectResult = useCallback(
        (result: SearchResult) => {
            setQuery(result.name);
            setOpen(false);
            setActiveIndex(-1);
            onResultSelect(result);
        },
        [onResultSelect],
    );

    const handleClear = useCallback(() => {
        setQuery('');
        setResults([]);
        setOpen(false);
        setHasSearched(false);
        setActiveIndex(-1);
        inputRef.current?.focus();
        onClear();
    }, [onClear]);

    function handleKeyDown(e: React.KeyboardEvent) {
        if (!open || results.length === 0) {
            if (e.key === 'Escape') {
                if (query) {
                    handleClear();
                } else {
                    inputRef.current?.blur();
                }
            }
            return;
        }

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                setActiveIndex((i) =>
                    i < results.length - 1 ? i + 1 : 0,
                );
                break;
            case 'ArrowUp':
                e.preventDefault();
                setActiveIndex((i) =>
                    i > 0 ? i - 1 : results.length - 1,
                );
                break;
            case 'Enter':
                e.preventDefault();
                if (activeIndex >= 0 && activeIndex < results.length) {
                    selectResult(results[activeIndex]);
                } else if (results.length > 0) {
                    selectResult(results[0]);
                }
                break;
            case 'Escape':
                e.preventDefault();
                setOpen(false);
                break;
        }
    }

    return (
        <div
            ref={containerRef}
            className="absolute top-4 left-4 z-20 w-[calc(100%-2rem)] md:w-[360px]"
        >
            {/* Search input */}
            <div className="bg-background/95 flex items-center gap-2 rounded-lg border px-3 py-2 shadow-sm backdrop-blur-sm">
                {loading ? (
                    <Loader2 className="text-muted-foreground h-4 w-4 shrink-0 animate-spin" />
                ) : (
                    <Search className="text-muted-foreground h-4 w-4 shrink-0" />
                )}
                <input
                    ref={inputRef}
                    type="text"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onFocus={() => {
                        if (results.length > 0) setOpen(true);
                    }}
                    onKeyDown={handleKeyDown}
                    placeholder="Search address, postal code, or city... (/)"
                    className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-sm outline-none"
                />
                {query && (
                    <button
                        onClick={handleClear}
                        className="text-muted-foreground hover:text-foreground shrink-0"
                    >
                        <X className="h-4 w-4" />
                    </button>
                )}
            </div>

            {/* Dropdown */}
            {open && (
                <div className="bg-background/95 mt-1 max-h-[320px] overflow-y-auto rounded-lg border shadow-lg backdrop-blur-sm">
                    {results.length > 0 ? (
                        <ul role="listbox">
                            {results.map((result, index) => (
                                <li
                                    key={result.id}
                                    role="option"
                                    aria-selected={index === activeIndex}
                                    className={`flex cursor-pointer items-start gap-2.5 px-3 py-2.5 transition-colors ${
                                        index === activeIndex
                                            ? 'bg-accent'
                                            : 'hover:bg-accent/50'
                                    }`}
                                    onClick={() => selectResult(result)}
                                    onMouseEnter={() =>
                                        setActiveIndex(index)
                                    }
                                >
                                    <div className="mt-0.5">
                                        {typeIcon(result.type)}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-medium">
                                            {result.name}
                                        </div>
                                        <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                            {typeLabel(result.type) && (
                                                <span className="text-muted-foreground/70">
                                                    {typeLabel(result.type)}
                                                </span>
                                            )}
                                            {typeLabel(result.type) &&
                                                result.secondary && (
                                                    <span className="text-muted-foreground/50">
                                                        &middot;
                                                    </span>
                                                )}
                                            <span className="truncate">
                                                {result.secondary}
                                            </span>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : hasSearched && !loading ? (
                        <div className="px-4 py-6 text-center">
                            <div className="text-muted-foreground text-sm">
                                No results found in Sweden.
                            </div>
                            <div className="text-muted-foreground mt-1 text-xs">
                                Try a street name, postal code, or city name.
                            </div>
                        </div>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function isInputFocused(): boolean {
    const el = document.activeElement;
    if (!el) return false;
    const tag = el.tagName.toLowerCase();
    return (
        tag === 'input' ||
        tag === 'textarea' ||
        tag === 'select' ||
        (el as HTMLElement).isContentEditable
    );
}
