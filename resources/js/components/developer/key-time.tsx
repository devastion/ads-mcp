export default function KeyTime({ value }: { value: string | null }) {
    if (!value) {
        return <span>Never</span>;
    }

    const date = new Date(value);

    return (
        <time dateTime={value} title={date.toUTCString()}>
            {date.toLocaleString(undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            })}
        </time>
    );
}
