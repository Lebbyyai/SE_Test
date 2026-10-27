import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { csrfHeaders } from '@/lib/csrf';
import { Head } from '@inertiajs/react';
import { Signature, Trash2, UploadCloud } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import SignatureCanvas from 'react-signature-canvas';
import { toast } from 'sonner';

type BreadcrumbItem = { title: string; href: string };
type Sig = { id: number; image_path: string; active: boolean; label: string };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'E‑Signatures', href: '/settings/signatures' }];

export default function SignaturesIndex() {
    const [list, setList] = useState<Sig[]>([]);
    const [uploading, setUploading] = useState(false);
    const [drawingOpen, setDrawingOpen] = useState(false);
    const [tab, setTab] = useState<'draw' | 'upload'>('draw');
    const [, setLoading] = useState(false);
    const sigPad = useRef<SignatureCanvas>(null);

    async function load() {
        setLoading(true);
        const r = await fetch('/api/signatures');
        if (r.ok) setList(await r.json());
        setLoading(false);
    }
    useEffect(() => {
        load();
    }, []);

    async function upload(e: { target: { files: FileList | null } }) {
        if (!e.target.files?.[0]) return;
        setUploading(true);
        const fd = new FormData();
        fd.append('image', e.target.files[0]);
        const res = await fetch('/api/signatures', { method: 'POST', body: fd, headers: csrfHeaders() });
        setUploading(false);
        if (res.ok) {
            toast.success('Signature uploaded!');
            load();
        } else {
            toast.error('Failed to upload signature.');
        }
    }

    async function saveDrawnSignature() {
        if (!sigPad.current) return;
        const canvas = sigPad.current.getCanvas();
        const dataUrl = canvas.toDataURL('image/png');
        const natural_width = canvas.width;
        const natural_height = canvas.height;
        const label = 'Drawn Signature';

        setUploading(true);
        const res = await fetch('/api/signatures', {
            method: 'POST',
            headers: csrfHeaders(true),
            body: JSON.stringify({
                image_base64: dataUrl,
                label,
                natural_width,
                natural_height,
            }),
        });
        setUploading(false);

        if (res.ok) {
            setDrawingOpen(false);
            sigPad.current.clear();
            toast.success('Signature created!');
            load();
        } else {
            toast.error('Failed to save signature.');
        }
    }

    async function activate(id: number) {
        setLoading(true);
        const res = await fetch(`/api/signatures/${id}/activate`, { method: 'PATCH', headers: csrfHeaders() });
        setLoading(false);
        if (res.ok) {
            toast.success('Signature activated!');
            load();
        } else {
            toast.error('Failed to activate signature.');
        }
    }

    function drawGuideLine() {
        if (sigPad.current) {
            const canvas = sigPad.current.getCanvas();
            const ctx = canvas.getContext('2d');
            if (ctx) {
                const margin = 40;
                const y = canvas.height - 80;
                ctx.save();
                ctx.strokeStyle = '#d1d5db';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(margin, y);
                ctx.lineTo(canvas.width - margin, y);
                ctx.stroke();
                ctx.restore();
            }
        }
    }

    useEffect(() => {
        if (drawingOpen && sigPad.current) {
            sigPad.current.clear();
            drawGuideLine();
        }
    }, [drawingOpen]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="E‑Signatures" />
            <SettingsLayout>
                <div className="space-y-10">
                    <div className="space-y-4">
                        <HeadingSmall title="E‑Signatures" description="Upload or draw your signature image." />
                        <div>
                            <Button size="sm" onClick={() => setDrawingOpen(true)}>
                                Draw Signature
                            </Button>
                            {uploading && <span className="ml-2 text-sm">Uploading...</span>}
                        </div>
                    </div>
                    <p className="font-medium">Active Signature</p>
                    <div className="grid gap-4 sm:grid-cols-3">
                        {list.map((s) => (
                            <div
                                key={s.id}
                                className={`flex flex-col items-center gap-2 rounded border bg-white p-3 ${s.active ? 'ring-2 ring-green-500' : ''}`}
                            >
                                <img src={`/storage/${s.image_path}`} className="max-h-24 object-contain" />
                                <Button size="sm" variant={s.active ? 'secondary' : 'default'} onClick={() => activate(s.id)}>
                                    {s.active ? 'Active' : 'Set Active'}
                                </Button>
                            </div>
                        ))}
                        {!list.length && <p className="col-span-full text-sm">No signatures.</p>}
                    </div>

                    {/* Signature Drawing Dialog */}
                    <Dialog open={drawingOpen} onOpenChange={setDrawingOpen}>
                        <DialogContent className="w-full max-w-4xl p-0">
                            <div className="flex flex-col gap-6 p-8">
                                <DialogHeader>
                                    <DialogTitle className="mb-2 text-xl font-semibold">Create Your Signature</DialogTitle>
                                </DialogHeader>

                                <Tabs value={tab} onValueChange={(v) => setTab(v as 'draw' | 'upload')} className="w-full">
                                    <div className="flex w-full items-center justify-between">
                                        <TabsList className="flex gap-2">
                                            <TabsTrigger value="draw" className="flex items-center gap-2 px-4 py-1 text-sm">
                                                <Signature className="h-4 w-4" /> Draw
                                            </TabsTrigger>
                                            <TabsTrigger value="upload" className="flex items-center gap-2 px-4 py-1 text-sm">
                                                <UploadCloud className="h-4 w-4" /> Upload
                                            </TabsTrigger>
                                        </TabsList>
                                        {tab === 'draw' && (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                className="ml-4"
                                                onClick={() => {
                                                    sigPad.current?.clear();
                                                    drawGuideLine();
                                                }}
                                            >
                                                <Trash2 className="inline h-4 w-4" />
                                                Clear
                                            </Button>
                                        )}
                                    </div>

                                    <div className="mt-4 w-full">
                                        <TabsContent value="draw" className="flex w-full flex-col items-center justify-center">
                                            <div className="flex w-full flex-col items-center gap-2">
                                                <div className="flex w-full justify-center">
                                                    <SignatureCanvas
                                                        ref={sigPad}
                                                        penColor="black"
                                                        backgroundColor="rgba(0,0,0,0)"
                                                        canvasProps={{
                                                            width: 690,
                                                            height: 300,
                                                            className: 'border border-zinc-300 rounded bg-zinc-100 shadow-lg mx-auto',
                                                            style: { cursor: 'crosshair' },
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        </TabsContent>

                                        <TabsContent value="upload" className="flex w-full flex-col items-center justify-center">
                                            <div
                                                className="hover:border-primary flex h-[180px] w-full cursor-pointer flex-col items-center justify-center rounded border-2 border-dashed border-neutral-300 bg-neutral-50 transition"
                                                onDrop={(e) => {
                                                    e.preventDefault();
                                                    if (e.dataTransfer.files?.[0]) upload({ target: { files: e.dataTransfer.files } });
                                                }}
                                                onDragOver={(e) => e.preventDefault()}
                                            >
                                                <UploadCloud className="mb-2 h-8 w-8 text-neutral-400" />
                                                <span className="mb-2 text-sm text-neutral-500">Drag & drop PNG here, or click to select</span>
                                                <input id="signature-upload" type="file" accept="image/png" onChange={upload} className="hidden" />
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => document.getElementById('signature-upload')?.click()}
                                                >
                                                    Browse
                                                </Button>
                                            </div>
                                            <p className="mt-2 text-xs text-neutral-500">Upload transparent PNG (max 1MB).</p>
                                        </TabsContent>
                                    </div>
                                </Tabs>

                                {/* Footer row: text + buttons */}
                                <div className="mt-4 flex w-full items-center justify-between">
                                    <span className="text-xs text-neutral-600">I understand this is a legal representation of my signature.</span>
                                    <div className="flex gap-2">
                                        {tab === 'draw' && (
                                            <Button size="sm" onClick={saveDrawnSignature} disabled={uploading}>
                                                {uploading ? 'Saving...' : 'Save Signature'}
                                            </Button>
                                        )}
                                        <Button size="sm" variant="secondary" onClick={() => setDrawingOpen(false)}>
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            {uploading && (
                                <div className="bg-opacity-70 absolute inset-0 z-50 flex items-center justify-center rounded-lg bg-white">
                                    <div className="flex flex-col items-center">
                                        <svg
                                            className="text-primary mb-2 h-8 w-8 animate-spin"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                        >
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                        </svg>
                                        <span className="text-primary text-sm">Saving signature...</span>
                                    </div>
                                </div>
                            )}
                        </DialogContent>
                    </Dialog>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
