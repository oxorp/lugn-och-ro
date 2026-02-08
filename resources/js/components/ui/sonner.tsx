import { FontAwesomeIcon } from "@fortawesome/react-fontawesome"
import {
  faCircleCheck,
  faCircleInfo,
  faOctagonXmark,
  faSpinnerThird,
  faTriangleExclamation,
} from "@/icons"
import { Toaster as Sonner, type ToasterProps } from "sonner"

const Toaster = ({ ...props }: ToasterProps) => {
  return (
    <Sonner
      className="toaster group"
      icons={{
        success: <FontAwesomeIcon icon={faCircleCheck} className="size-4" />,
        info: <FontAwesomeIcon icon={faCircleInfo} className="size-4" />,
        warning: <FontAwesomeIcon icon={faTriangleExclamation} className="size-4" />,
        error: <FontAwesomeIcon icon={faOctagonXmark} className="size-4" />,
        loading: <FontAwesomeIcon icon={faSpinnerThird} spin className="size-4" />,
      }}
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          "--border-radius": "var(--radius)",
        } as React.CSSProperties
      }
      {...props}
    />
  )
}

export { Toaster }
