<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Maintenance - The Forge</title>
    <link
        rel="icon"
        type="image/png"
        href="/favicon-96x96.png"
        sizes="96x96"
    />
    <link
        rel="icon"
        type="image/svg+xml"
        href="/favicon.svg"
    />
    <link
        rel="shortcut icon"
        href="/favicon.ico"
    />
    {{-- Self-contained: shown while the app is down, so it can rely on neither the build assets nor any third party. --}}
    <style>
        *,
        ::before,
        ::after {
            box-sizing: border-box;
            margin: 0;
        }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: #1f2937;
            font-family: ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol',
                'Noto Color Emoji';
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        button {
            font: inherit;
            color: inherit;
            background: none;
            border: 0;
            padding: 0;
            cursor: pointer;
        }

        .page {
            display: flex;
            flex-grow: 1;
            align-items: center;
            justify-content: center;
            padding: 0 1rem;
        }

        #maintenance {
            width: 100%;
            max-width: 42rem;
            transition: max-width 0.3s;
        }

        #maintenance[data-state='game'] {
            max-width: 56rem;
        }

        .card {
            border-radius: 0.5rem;
            background: #111827;
            padding: 2rem;
            text-align: center;
            box-shadow:
                0 10px 15px -3px rgb(0 0 0 / 0.1),
                0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        .toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 4rem;
            height: 4rem;
            margin-bottom: 1.5rem;
            border-radius: 9999px;
            background: rgb(127 29 29 / 0.2);
            color: #f87171;
            transition:
                background-color 0.3s,
                color 0.3s;
        }

        [data-state='calm'] .toggle {
            background: rgb(22 78 99 / 0.2);
            color: #22d3ee;
        }

        .toggle svg {
            width: 2rem;
            height: 2rem;
        }

        .panel {
            display: none;
        }

        [data-state='calm'] .panel-calm,
        [data-state='snarky'] .panel-snarky,
        [data-state='game'] .panel-game {
            display: block;
        }

        h1 {
            margin-bottom: 1rem;
            color: #fff;
            font-size: 1.875rem;
            line-height: 2.25rem;
            font-weight: 700;
        }

        .panel p {
            color: #9ca3af;
            font-size: 1.125rem;
            line-height: 1.75rem;
        }

        .panel p button {
            transition: color 0.3s;
        }

        .panel p button:hover {
            color: #d1d5db;
        }

        .panel-game iframe {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            border: 0;
            border-radius: 0.5rem;
        }

        .panel-game p {
            margin-top: 1rem;
            color: #6b7280;
            font-size: 0.875rem;
            line-height: 1.25rem;
        }

        @media (min-width: 768px) {
            .card {
                padding: 3rem;
            }

            h1 {
                font-size: 2.25rem;
                line-height: 2.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <div
            id="maintenance"
            data-state="calm"
        >
            <div class="card">
                <button
                    id="toggle"
                    type="button"
                    class="toggle"
                >
                    <svg
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"
                        />
                    </svg>
                </button>
                <div class="panel panel-calm">
                    <h1>Down for Maintenance</h1>
                    <p>We're performing some scheduled maintenance and will be back shortly. Thanks for your patience.
                    </p>
                </div>
                <div class="panel panel-snarky">
                    <h1>Yes, It's Still Down</h1>
                    <p>Seriously, though, you need to be patient. I don't want to see you come into our Discord server
                        and say, "The server is down." <strong>We know.</strong> We're the ones that put it down. Be
                        patient. Good things are coming. Seriously. <button
                            id="play"
                            type="button"
                        >Touch grass, or something,</button> idk... <em>nerds</em>.</p>
                </div>
                <div class="panel panel-game">
                    <h1>EXFIL: Extraction Runner</h1>
                    <div id="game-frame"></div>
                    <p>Grass is overrated. The wrench takes you back.</p>
                </div>
            </div>
        </div>
    </div>
    <script>
        const maintenance = document.getElementById('maintenance');
        const gameFrame = document.getElementById('game-frame');
        let snarky = document.cookie.includes('maintenance_visited=');
        let game = false;

        document.cookie = 'maintenance_visited=1; max-age=60; path=/';

        function render() {
            maintenance.dataset.state = game ? 'game' : snarky ? 'snarky' : 'calm';

            if (!game) {
                gameFrame.replaceChildren();
            } else if (!gameFrame.firstChild) {
                const iframe = document.createElement('iframe');
                iframe.src = '/exfil.html';
                iframe.title = 'EXFIL: Extraction Runner';
                gameFrame.appendChild(iframe);
            }
        }

        document.getElementById('toggle').addEventListener('click', () => {
            if (game) {
                game = false;
            } else {
                snarky = !snarky;
            }
            render();
        });

        document.getElementById('play').addEventListener('click', () => {
            game = true;
            render();
        });

        render();
    </script>
</body>

</html>
