export interface ScoreColorConfig {
    gradient_stops: Record<number, string>;
    labels: Array<{
        min: number;
        max: number;
        label_sv: string;
        label_en: string;
        color: string;
    }>;
    no_data: string;
    no_data_border: string;
    selected_border: string;
    school_markers: {
        high: string;
        medium: string;
        low: string;
        no_data: string;
    };
    indicator_bar: {
        good: string;
        bad: string;
        neutral: string;
    };
}
