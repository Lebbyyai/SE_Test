import { Button } from '@/components/ui/button';
import { csrfHeaders } from '@/lib/csrf';
import { errorMessage } from '@/lib/error-message';
import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Rnd } from 'react-rnd';
import { toast } from 'sonner';

// PDF.js imports for Vite/React
import * as pdfjsLib from 'pdfjs-dist';
import workerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

// Set the worker source for pdfjs
pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;

type Field = {
    id: string;
    key: string;
    page: number;
    x: number;
    y: number;
    width: number;
    height: number;
    type: 'text' | 'multiline' | 'signature';
    font_size?: number;
};
const KEYS = [
    'student.full_name',
    'student.program',
    'request.thesis_title',
    'request.defense_type',
    'schedule.date',
    'schedule.time',
    'signature.adviser',
    'signature.coordinator',
    'signature.dean',
    'today.date',
    'coordinator.full_name',
    'adviser.full_name',
    'dean.full_name',
];

interface Props {
    templateId: number;
    template: { name: string; fields?: Field[] };
}

export default function TemplateEditorPage({ templateId, template }: Props) {
    const [pdfPages, setPdfPages] = useState<HTMLCanvasElement[]>([]);
    const [fields, setFields] = useState<Field[]>(template?.fields || []);
    const [selectedPage, setSelectedPage] = useState(1);
    const [selectedField, setSelectedField] = useState<Field | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    // Real-time field position state
    const [currentFieldPosition, setCurrentFieldPosition] = useState<{
        x: number;
        y: number;
        width: number;
        height: number;
    } | null>(null);

    useEffect(() => {
        (async () => {
            setLoading(true);
            const meta = await fetch(`/api/document-templates/${templateId}`).then((r) => r.json());
            const url = `/storage/${meta.file_path}`.replace('/storage/storage', '/storage');
            console.log('PDF URL:', url);
            try {
                const pdf = await pdfjsLib.getDocument(url).promise;
                const canv: HTMLCanvasElement[] = [];
                for (let i = 1; i <= pdf.numPages; i++) {
                    const pg = await pdf.getPage(i);
                    const vp = pg.getViewport({ scale: 1.05 });
                    const c = document.createElement('canvas');
                    c.width = vp.width;
                    c.height = vp.height;
                    await pg.render({ canvasContext: c.getContext('2d')!, viewport: vp, canvas: c }).promise;
                    canv.push(c);
                }
                setPdfPages(canv);
            } catch (err: unknown) {
                alert('PDF load error: ' + errorMessage(err));
                console.error('PDF load error:', err);
                setPdfPages([]);
            }
            setLoading(false);
        })();
    }, [templateId]);

    function add() {
        const newField: Field = {
            id: crypto.randomUUID(),
            key: KEYS[0],
            page: selectedPage,
            x: 40,
            y: 40,
            width: 200,
            height: 30,
            type: 'text',
            font_size: 11,
        };
        setFields((f) => [...f, newField]);
        setSelectedField(newField);
        setCurrentFieldPosition({ x: 40, y: 40, width: 200, height: 30 });
    }

    async function save() {
        setSaving(true);
        try {
            const response = await fetch(`/api/document-templates/${templateId}/fields`, {
                method: 'PUT',
                headers: csrfHeaders(true),
                body: JSON.stringify({ fields, fields_meta: { canvas_width: pdfPages[0]?.width, canvas_height: pdfPages[0]?.height } }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Unable to save the template mapping.');
            toast.success('Template mapping saved.');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Unable to connect. Please try again.');
        } finally {
            setSaving(false);
        }
    }

    const handleFieldSelect = (field: Field) => {
        setSelectedField(field);
        setSelectedPage(field.page);
        setCurrentFieldPosition({
            x: field.x,
            y: field.y,
            width: field.width,
            height: field.height,
        });
    };

    const handleFieldUpdate = (fieldId: string, updates: Partial<Field>) => {
        setFields((fs) => fs.map((x) => (x.id === fieldId ? { ...x, ...updates } : x)));
        if (selectedField?.id === fieldId) {
            const updatedField = { ...selectedField, ...updates };
            setSelectedField(updatedField);
            if (updates.x !== undefined || updates.y !== undefined || updates.width !== undefined || updates.height !== undefined) {
                setCurrentFieldPosition({
                    x: updates.x ?? updatedField.x,
                    y: updates.y ?? updatedField.y,
                    width: updates.width ?? updatedField.width,
                    height: updates.height ?? updatedField.height,
                });
            }
        }
    };

    // Real-time drag handler
    const handleDrag = (fieldId: string, data: { x: number; y: number }) => {
        setCurrentFieldPosition((prev) => (prev ? { ...prev, x: data.x, y: data.y } : null));
    };

    // Real-time resize handler
    const handleResize = (fieldId: string, ref: HTMLElement, position: { x: number; y: number }) => {
        setCurrentFieldPosition({
            x: position.x,
            y: position.y,
            width: ref.offsetWidth,
            height: ref.offsetHeight,
        });
    };

    return (
        <div style={{ width: '100vw', height: '100vh', background: '#f8fafc', overflow: 'hidden' }}>
            <Head title={`Edit Template – ${template?.name || ''}`} />
            <div className="flex h-full">
                {/* Sidebar: all controls and info - Fully scrollable */}
                <div className="flex w-80 flex-col overflow-hidden border-r bg-white">
                    <div className="border-b p-6">
                        <div className="mb-1 text-lg font-bold">Field mapping: {template?.name}</div>
                        <div className="mb-4 text-xs text-neutral-500">Place dynamic text and signatures onto the PDF pages.</div>
                        <div className="mb-2 flex gap-2">
                            <Button onClick={add}>
                                <Plus size={16} /> Create Map
                            </Button>
                            <Button onClick={save} disabled={saving} variant="destructive">
                                {saving ? 'Saving...' : 'Save'}
                            </Button>
                        </div>
                    </div>

                    {/* Scrollable content area */}
                    <div className="flex-1 space-y-6 overflow-y-auto p-6">
                        {/* Fields List - Takes remaining space */}
                        <div className="flex-1 rounded border bg-white p-3">
                            <div className="mb-2 text-sm font-semibold">Fields on Page {selectedPage}</div>
                            <div className="max-h-96 space-y-2 overflow-y-auto">
                                {fields
                                    .filter((f) => f.page === selectedPage)
                                    .map((f) => (
                                        <div
                                            key={f.id}
                                            className={`cursor-pointer rounded border p-2 transition-colors ${
                                                selectedField?.id === f.id ? 'border-blue-400 bg-blue-100' : 'bg-gray-50 hover:bg-gray-100'
                                            }`}
                                            onClick={() => handleFieldSelect(f)}
                                        >
                                            <select
                                                className="mb-1 w-full rounded border bg-white px-2 py-1 text-xs"
                                                value={f.key}
                                                onChange={(e) => handleFieldUpdate(f.id, { key: e.target.value })}
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                {KEYS.map((k) => (
                                                    <option key={k}>{k}</option>
                                                ))}
                                            </select>
                                            <div className="flex items-center justify-between text-xs">
                                                <span className="text-gray-600 capitalize">{f.type}</span>
                                                <div className="flex gap-1">
                                                    <span className="font-mono text-gray-500">
                                                        {Math.round(f.x)},{Math.round(f.y)}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        className="ml-2 text-red-600 hover:text-red-800"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setFields((fs) => fs.filter((x) => x.id !== f.id));
                                                            if (selectedField?.id === f.id) {
                                                                setSelectedField(null);
                                                                setCurrentFieldPosition(null);
                                                            }
                                                        }}
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                {!fields.filter((f) => f.page === selectedPage).length && (
                                    <p className="py-4 text-center text-gray-500 italic">No fields on this page.</p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Document Preview - All Pages */}
                <div className="flex flex-1 flex-col overflow-hidden bg-neutral-50">
                    {/* Minimal position overlay - top right corner */}
                    {selectedField && currentFieldPosition && (
                        <div className="fixed top-4 right-4 z-50 rounded bg-black/90 px-3 py-2 font-mono text-xs text-white shadow-lg">
                            <div>
                                X:{Math.round(currentFieldPosition.x)} Y:{Math.round(currentFieldPosition.y)}
                            </div>
                            <div>
                                W:{Math.round(currentFieldPosition.width)} H:{Math.round(currentFieldPosition.height)}
                            </div>
                        </div>
                    )}

                    <div className="flex-1 overflow-auto p-6">
                        <div className="flex flex-col items-center space-y-8">
                            {loading && (
                                <div className="flex h-32 items-center justify-center">
                                    <p className="text-sm text-gray-600">Loading PDF pages...</p>
                                </div>
                            )}

                            {pdfPages.map((canvas, index) => {
                                const pageNumber = index + 1;
                                const isActivePage = selectedPage === pageNumber;

                                return (
                                    <div
                                        key={index}
                                        className={`relative transition-all duration-200 ${
                                            isActivePage ? 'ring-2 ring-blue-500 ring-offset-2' : 'ring-1 ring-gray-200'
                                        }`}
                                        style={{
                                            width: canvas?.width,
                                            height: canvas?.height,
                                            boxShadow: '0 2px 16px #0001',
                                            background: '#fff',
                                            borderRadius: 8,
                                            overflow: 'hidden',
                                        }}
                                        onClick={() => setSelectedPage(pageNumber)}
                                    >
                                        {/* Page Header */}
                                        <div className="absolute top-2 left-2 z-10 rounded bg-black/70 px-2 py-1 text-xs text-white">
                                            Page {pageNumber}
                                        </div>

                                        {canvas && (
                                            <>
                                                <canvas
                                                    width={canvas.width}
                                                    height={canvas.height}
                                                    ref={(el) => {
                                                        if (el && canvas) {
                                                            const ctx = el.getContext('2d');
                                                            ctx?.drawImage(canvas, 0, 0);
                                                        }
                                                    }}
                                                    style={{
                                                        position: 'absolute',
                                                        left: 0,
                                                        top: 0,
                                                        width: '100%',
                                                        height: '100%',
                                                    }}
                                                />

                                                {/* Render fields for this page */}
                                                {fields
                                                    .filter((f) => f.page === pageNumber)
                                                    .map((f) => (
                                                        <Rnd
                                                            key={f.id}
                                                            bounds="parent"
                                                            size={{ width: f.width, height: f.height }}
                                                            position={{ x: f.x, y: f.y }}
                                                            onDrag={(e, d) => {
                                                                handleDrag(f.id, d);
                                                            }}
                                                            onResize={(e, direction, ref, delta, position) => {
                                                                handleResize(f.id, ref, position);
                                                            }}
                                                            onDragStop={(e, d) => {
                                                                handleFieldUpdate(f.id, { x: d.x, y: d.y });
                                                            }}
                                                            onResizeStop={(e, direction, ref, delta, position) => {
                                                                handleFieldUpdate(f.id, {
                                                                    width: ref.offsetWidth,
                                                                    height: ref.offsetHeight,
                                                                    x: position.x,
                                                                    y: position.y,
                                                                });
                                                            }}
                                                            onMouseDown={() => handleFieldSelect(f)}
                                                            className={`absolute flex cursor-move items-center justify-center border text-[10px] ${
                                                                f.type === 'signature'
                                                                    ? 'border-green-600 bg-green-200/30'
                                                                    : f.type === 'multiline'
                                                                      ? 'border-purple-600 bg-purple-200/30'
                                                                      : 'border-blue-600 bg-blue-200/30'
                                                            } ${selectedField?.id === f.id ? 'z-20 ring-2 ring-yellow-400 ring-offset-1' : 'z-10'}`}
                                                        >
                                                            <div className="flex h-full w-full flex-col items-center justify-center px-1">
                                                                <span
                                                                    className={`font-bold ${
                                                                        f.type === 'signature'
                                                                            ? 'text-green-700'
                                                                            : f.type === 'multiline'
                                                                              ? 'text-purple-700'
                                                                              : 'text-blue-700'
                                                                    }`}
                                                                >
                                                                    {f.type.charAt(0).toUpperCase() + f.type.slice(1)}
                                                                </span>
                                                                <span className="text-center text-[10px] break-all text-neutral-700">
                                                                    {f.key.split('.').pop()}
                                                                </span>
                                                                {f.type === 'signature' && <span className="text-xs text-green-700">Sign here</span>}
                                                                {f.type === 'multiline' && (
                                                                    <div className="text-xs leading-tight text-purple-700 opacity-70">
                                                                        Line 1<br />
                                                                        Line 2<br />
                                                                        Line 3
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </Rnd>
                                                    ))}
                                            </>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
