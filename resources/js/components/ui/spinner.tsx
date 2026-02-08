import { FontAwesomeIcon } from "@fortawesome/react-fontawesome"
import { faSpinnerThird } from "@/icons"

import { cn } from "@/lib/utils"

function Spinner({ className, ...props }: Omit<React.ComponentProps<typeof FontAwesomeIcon>, 'icon'>) {
  return (
    <FontAwesomeIcon
      icon={faSpinnerThird}
      spin
      role="status"
      aria-label="Loading"
      className={cn("size-4", className)}
      {...props}
    />
  )
}

export { Spinner }
