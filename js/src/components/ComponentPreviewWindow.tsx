// ComponentPreviewWindow — draggable, resizable floating preview of an SDC component.
import React, { useState, useRef, useCallback, useEffect } from 'react';

interface ComponentPreviewWindowProps {
    componentId: string;
    isOpen: boolean;
    onClose: () => void;
    /** External refresh trigger — increment to force iframe reload. */
    refreshKey?: number;
}

export function ComponentPreviewWindow({ componentId, isOpen, onClose, refreshKey: externalKey = 0 }: ComponentPreviewWindowProps) {
    const [position, setPosition] = useState({ x: window.innerWidth - 560, y: 60 });
    const [size, setSize] = useState({ width: 520, height: 420 });
    const [isDragging, setIsDragging] = useState(false);
    const [isResizing, setIsResizing] = useState(false);
    const [refreshKey, setRefreshKey] = useState(0);

    const dragOffset = useRef({ x: 0, y: 0 });
    const windowRef = useRef<HTMLDivElement>(null);

    // Build the preview URL from the component name.
    const previewUrl = `/sdc-preview/${encodeURIComponent(componentId)}`;

    // Refresh iframe when componentId or external key changes.
    useEffect(() => {
        setRefreshKey(k => k + 1);
    }, [componentId, externalKey]);

    // ── Drag handlers ──
    const handleDragStart = useCallback((e: React.MouseEvent) => {
        if ((e.target as HTMLElement).closest('.cpw-btn')) return;
        setIsDragging(true);
        dragOffset.current = {
            x: e.clientX - position.x,
            y: e.clientY - position.y,
        };
        e.preventDefault();
    }, [position]);

    // ── Resize handlers ──
    const resizeStart = useRef({ x: 0, y: 0, w: 0, h: 0 });

    const handleResizeStart = useCallback((e: React.MouseEvent) => {
        setIsResizing(true);
        resizeStart.current = {
            x: e.clientX,
            y: e.clientY,
            w: size.width,
            h: size.height,
        };
        e.preventDefault();
        e.stopPropagation();
    }, [size]);

    // Global mouse handlers for drag + resize.
    useEffect(() => {
        if (!isDragging && !isResizing) return;

        const handleMove = (e: MouseEvent) => {
            if (isDragging) {
                setPosition({
                    x: Math.max(0, e.clientX - dragOffset.current.x),
                    y: Math.max(0, e.clientY - dragOffset.current.y),
                });
            }
            if (isResizing) {
                const dx = e.clientX - resizeStart.current.x;
                const dy = e.clientY - resizeStart.current.y;
                setSize({
                    width: Math.max(300, resizeStart.current.w + dx),
                    height: Math.max(200, resizeStart.current.h + dy),
                });
            }
        };

        const handleUp = () => {
            setIsDragging(false);
            setIsResizing(false);
        };

        window.addEventListener('mousemove', handleMove);
        window.addEventListener('mouseup', handleUp);
        return () => {
            window.removeEventListener('mousemove', handleMove);
            window.removeEventListener('mouseup', handleUp);
        };
    }, [isDragging, isResizing]);

    if (!isOpen) return null;

    return (
        <div
            ref={windowRef}
            className="cpw"
            style={{
                left: position.x,
                top: position.y,
                width: size.width,
                height: size.height,
            }}
        >
            {/* Title bar */}
            <div className="cpw-titlebar" onMouseDown={handleDragStart}>
                <span className="cpw-title">🧩 Component Preview</span>
                <div className="cpw-actions">
                    <button
                        className="cpw-btn"
                        type="button"
                        onClick={() => setRefreshKey(k => k + 1)}
                        title="Refresh preview"
                    >🔄</button>
                    <button
                        className="cpw-btn cpw-btn--close"
                        type="button"
                        onClick={onClose}
                        title="Close preview"
                    >✕</button>
                </div>
            </div>

            {/* Component name label */}
            <div className="cpw-component-id">{componentId}</div>

            {/* Iframe */}
            <div className="cpw-body">
                {(isDragging || isResizing) && <div className="cpw-overlay" />}
                <iframe
                    key={refreshKey}
                    src={previewUrl}
                    className="cpw-iframe"
                    title="Component Preview"
                    sandbox="allow-scripts allow-same-origin"
                />
            </div>

            {/* Resize handle */}
            <div className="cpw-resize" onMouseDown={handleResizeStart} />
        </div>
    );
}
