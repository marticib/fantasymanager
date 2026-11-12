import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Info } from 'lucide-react'

const WIDTH = 280

/**
 * A small "i" that explains a term. Unlike a `title` tooltip it works on
 * touch screens (tap to open, tap outside to close) and on desktop it also
 * opens on hover. Rendered in a portal so table scroll containers and
 * transformed cards can't clip it.
 */
export default function InfoTip({ children, label = 'Què vol dir?', className = '' }) {
  const [pinned, setPinned] = useState(false)
  const [hovered, setHovered] = useState(false)
  const [pos, setPos] = useState(null)
  const buttonRef = useRef(null)
  const popRef = useRef(null)
  const visible = pinned || hovered

  const place = () => {
    const r = buttonRef.current?.getBoundingClientRect()
    if (!r) return
    const width = Math.min(WIDTH, window.innerWidth - 32)
    const left = Math.max(16, Math.min(r.left + r.width / 2 - width / 2, window.innerWidth - width - 16))
    const above = r.bottom + 160 > window.innerHeight && r.top > 160
    setPos(above ? { left, width, bottom: window.innerHeight - r.top + 8 } : { left, width, top: r.bottom + 8 })
  }

  useEffect(() => {
    if (!visible) return undefined
    place()
    const close = () => {
      setPinned(false)
      setHovered(false)
    }
    const onPointerDown = (e) => {
      if (buttonRef.current?.contains(e.target) || popRef.current?.contains(e.target)) return
      close()
    }
    const onKey = (e) => e.key === 'Escape' && close()
    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKey)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKey)
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('resize', close)
    }
  }, [visible])

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        aria-label={label}
        aria-expanded={visible}
        onClick={(e) => {
          // Lives inside sortable headers / clickable cards: don't trigger them.
          e.preventDefault()
          e.stopPropagation()
          setPinned((p) => !p)
        }}
        onPointerEnter={(e) => e.pointerType === 'mouse' && setHovered(true)}
        onPointerLeave={(e) => e.pointerType === 'mouse' && setHovered(false)}
        className={`inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full align-middle text-muted-foreground hover:text-primary focus-visible:text-primary ${className}`}
      >
        <Info className="h-3.5 w-3.5" />
      </button>
      {visible &&
        pos &&
        createPortal(
          <div
            ref={popRef}
            role="tooltip"
            style={{ position: 'fixed', ...pos }}
            className="panel elevated z-[60] animate-pop p-3 text-left text-xs font-normal normal-case leading-relaxed tracking-normal text-foreground shadow-lg"
          >
            {children}
          </div>,
          document.body,
        )}
    </>
  )
}
