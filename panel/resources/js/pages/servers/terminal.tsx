import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { PowerIcon, TerminalSquareIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

interface Props {
    server: { id: string; name: string; public_ip: string | null; status: string };
    terminalToken: string;
    systemUsers: string[];
    gatewayUrl: string;
}

type ConnectionState = 'disconnected' | 'connecting' | 'connected' | 'error';

export default function ServerTerminal({ server, terminalToken, systemUsers, gatewayUrl }: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const termRef = useRef<Terminal | null>(null);
    const fitRef = useRef<FitAddon | null>(null);
    const wsRef = useRef<WebSocket | null>(null);

    const [selectedUser, setSelectedUser] = useState(systemUsers[0] ?? 'root');
    const [connectionState, setConnectionState] = useState<ConnectionState>('disconnected');

    const connect = () => {
        // Clean up any existing terminal + WebSocket before reconnecting.
        if (wsRef.current) {
            wsRef.current.close();
            wsRef.current = null;
        }
        if (termRef.current) {
            termRef.current.dispose();
            termRef.current = null;
        }

        setConnectionState('connecting');

        // Fetch a fresh session token (the old one was single-use).
        fetch(route('servers.terminal', server.id), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((res) => res.json())
            .then((data) => {
                if (!data.terminalToken) {
                    throw new Error('No token received');
                }
                openTerminal(data.terminalToken);
            })
            .catch(() => {
                // Fallback: use the original token (may fail if expired).
                openTerminal(terminalToken);
            });
    };

    const openTerminal = (token: string) => {
        // Initialize xterm.js
        const term = new Terminal({
            cursorBlink: true,
            fontSize: 14,
            fontFamily: 'Menlo, Monaco, "Courier New", monospace',
            theme: {
                background: '#0c0c0c',
                foreground: '#cccccc',
                cursor: '#ffffff',
            },
        });

        const fit = new FitAddon();
        term.loadAddon(fit);
        if (containerRef.current) {
            containerRef.current.innerHTML = '';
            term.open(containerRef.current);
        }
        fit.fit();

        termRef.current = term;
        fitRef.current = fit;

        term.writeln('\x1b[33mConnecting to terminal...\x1b[0m');

        // Open WebSocket to the gateway with the fresh token.
        const url = `${gatewayUrl}?server=${server.id}&token=${token}&user=${encodeURIComponent(selectedUser)}&cols=${term.cols}&rows=${term.rows}`;
        const ws = new WebSocket(url);
        wsRef.current = ws;

        ws.onopen = () => {
            setConnectionState('connected');
            term.clear();

            // Send keystrokes to the gateway.
            term.onData((data: string) => {
                // Base64-encode the keystrokes.
                const encoded = btoa(data);
                ws.send(JSON.stringify({ type: 'input', data: encoded }));
            });

            // Handle terminal resize.
            term.onResize(({ cols, rows }) => {
                ws.send(JSON.stringify({ type: 'resize', cols, rows }));
            });
        };

        ws.onmessage = async (event) => {
            try {
                const msg = JSON.parse(event.data);

                if (msg.type === 'terminal_data' && msg.payload?.data) {
                    // Base64-decode and write to the terminal.
                    const decoded = atob(msg.payload.data);
                    term.write(decoded);
                } else if (msg.type === 'terminal_exited') {
                    term.writeln('\r\n\x1b[31m[Session ended]\x1b[0m');
                    setConnectionState('disconnected');
                } else if (msg.type === 'error') {
                    term.writeln(`\r\n\x1b[31m[Error: ${msg.message}]\x1b[0m`);
                    setConnectionState('error');
                }
            } catch {
                // Ignore malformed messages.
            }
        };

        ws.onerror = () => {
            term.writeln('\r\n\x1b[31m[Connection error]\x1b[0m');
            setConnectionState('error');
        };

        ws.onclose = () => {
            setConnectionState('disconnected');
        };

        // Handle window resize.
        const onResize = () => fit.fit();
        window.addEventListener('resize', onResize);
    };

    const disconnect = () => {
        if (wsRef.current) {
            wsRef.current.close();
            wsRef.current = null;
        }
        if (termRef.current) {
            termRef.current.dispose();
            termRef.current = null;
        }
        setConnectionState('disconnected');
    };

    // Cleanup on unmount.
    useEffect(() => {
        return () => {
            if (wsRef.current) wsRef.current.close();
            if (termRef.current) termRef.current.dispose();
        };
    }, []);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Terminal', href: `/servers/${server.id}/terminal` },
    ];

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title="Terminal" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            <TerminalSquareIcon className="h-5 w-5" />
                            Terminal
                        </h1>
                    </div>
                    <div className="flex items-center gap-3">
                        <div className="flex items-center gap-2">
                            <Label className="text-sm">User:</Label>
                            <Select value={selectedUser} onValueChange={setSelectedUser} disabled={connectionState === 'connected'}>
                                <SelectTrigger className="w-36">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {systemUsers.map((user) => (
                                        <SelectItem key={user} value={user}>{user}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {connectionState === 'connected' || connectionState === 'connecting' ? (
                            <Button variant="destructive" size="sm" onClick={disconnect} disabled={connectionState === 'connecting'}>
                                <PowerIcon className="mr-1.5 h-4 w-4" />
                                Disconnect
                            </Button>
                        ) : (
                            <Button size="sm" onClick={connect} disabled={server.status !== 'online'}>
                                <PowerIcon className="mr-1.5 h-4 w-4" />
                                Connect
                            </Button>
                        )}
                    </div>
                </div>

                {server.status !== 'online' && (
                    <Card>
                        <CardContent className="py-6 text-center">
                            <p className="text-muted-foreground text-sm">
                                The agent on this server is not connected. Terminal is unavailable.
                            </p>
                        </CardContent>
                    </Card>
                )}

                <Card className="flex flex-1 flex-col overflow-hidden">
                    <CardHeader className="py-2">
                        <CardTitle className="text-sm">
                            {selectedUser}@{server.public_ip ?? server.id}
                            {connectionState === 'connected' && (
                                <span className="ml-2 text-green-500">● connected</span>
                            )}
                            {connectionState === 'connecting' && (
                                <span className="ml-2 animate-pulse text-yellow-500">● connecting…</span>
                            )}
                            {connectionState === 'disconnected' && (
                                <span className="text-muted-foreground ml-2">● disconnected</span>
                            )}
                            {connectionState === 'error' && (
                                <span className="ml-2 text-red-500">● error</span>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex-1 overflow-hidden p-0">
                        <div
                            ref={containerRef}
                            className="h-full min-h-[400px] bg-zinc-950 p-2"
                        />
                    </CardContent>
                </Card>
            </div>
        </ServerLayout>
    );
}
