/**
 * FontAwesome 7 Pro — central icon library.
 *
 * Every icon used anywhere in the app is imported here and added to the
 * library once.  Components then reference icons by object (`icon={faXmark}`)
 * or by name string (`icon="xmark"`).  Tree-shaking removes unused icons.
 */
import { config, library } from '@fortawesome/fontawesome-svg-core';
import '@fortawesome/fontawesome-svg-core/styles.css';

// Disable auto-CSS injection (we import the stylesheet above instead)
config.autoAddCss = false;

// ── Solid icons (fas) ────────────────────────────────────────────────
import { faAnglesLeft } from '@fortawesome/pro-solid-svg-icons/faAnglesLeft';
import { faAnglesRight } from '@fortawesome/pro-solid-svg-icons/faAnglesRight';
import { faArrowDown } from '@fortawesome/pro-solid-svg-icons/faArrowDown';
import { faArrowLeft } from '@fortawesome/pro-solid-svg-icons/faArrowLeft';
import { faArrowRight } from '@fortawesome/pro-solid-svg-icons/faArrowRight';
import { faArrowUp } from '@fortawesome/pro-solid-svg-icons/faArrowUp';
import { faArrowUpRightFromSquare } from '@fortawesome/pro-solid-svg-icons/faArrowUpRightFromSquare';
import { faArrowsRotate } from '@fortawesome/pro-solid-svg-icons/faArrowsRotate';
import { faBars } from '@fortawesome/pro-solid-svg-icons/faBars';
import { faBookOpen } from '@fortawesome/pro-solid-svg-icons/faBookOpen';
import { faBus } from '@fortawesome/pro-solid-svg-icons/faBus';
import { faCalendarClock } from '@fortawesome/pro-solid-svg-icons/faCalendarClock';
import { faCartShopping } from '@fortawesome/pro-solid-svg-icons/faCartShopping';
import { faChartColumn } from '@fortawesome/pro-solid-svg-icons/faChartColumn';
import { faCheck } from '@fortawesome/pro-solid-svg-icons/faCheck';
import { faChevronDown } from '@fortawesome/pro-solid-svg-icons/faChevronDown';
import { faChevronLeft } from '@fortawesome/pro-solid-svg-icons/faChevronLeft';
import { faChevronRight } from '@fortawesome/pro-solid-svg-icons/faChevronRight';
import { faChevronUp } from '@fortawesome/pro-solid-svg-icons/faChevronUp';
import { faCircle } from '@fortawesome/pro-solid-svg-icons/faCircle';
import { faCircleCheck } from '@fortawesome/pro-solid-svg-icons/faCircleCheck';
import { faCircleExclamation } from '@fortawesome/pro-solid-svg-icons/faCircleExclamation';
import { faCircleInfo } from '@fortawesome/pro-solid-svg-icons/faCircleInfo';
import { faCircleXmark } from '@fortawesome/pro-solid-svg-icons/faCircleXmark';
import { faClock } from '@fortawesome/pro-solid-svg-icons/faClock';
import { faDatabase } from '@fortawesome/pro-solid-svg-icons/faDatabase';
import { faDesktop } from '@fortawesome/pro-solid-svg-icons/faDesktop';
import { faEllipsis } from '@fortawesome/pro-solid-svg-icons/faEllipsis';
import { faEnvelope } from '@fortawesome/pro-solid-svg-icons/faEnvelope';
import { faFloppyDisk } from '@fortawesome/pro-solid-svg-icons/faFloppyDisk';
import { faFolder } from '@fortawesome/pro-solid-svg-icons/faFolder';
import { faGear } from '@fortawesome/pro-solid-svg-icons/faGear';
import { faGraduationCap } from '@fortawesome/pro-solid-svg-icons/faGraduationCap';
import { faGrid2 } from '@fortawesome/pro-solid-svg-icons/faGrid2';
import { faLocationArrow } from '@fortawesome/pro-solid-svg-icons/faLocationArrow';
import { faLocationDot } from '@fortawesome/pro-solid-svg-icons/faLocationDot';
import { faLock } from '@fortawesome/pro-solid-svg-icons/faLock';
import { faLockKeyhole } from '@fortawesome/pro-solid-svg-icons/faLockKeyhole';
import { faMagnifyingGlass } from '@fortawesome/pro-solid-svg-icons/faMagnifyingGlass';
import { faMapPin } from '@fortawesome/pro-solid-svg-icons/faMapPin';
import { faMinus } from '@fortawesome/pro-solid-svg-icons/faMinus';
import { faMoon } from '@fortawesome/pro-solid-svg-icons/faMoon';
import { faOctagonXmark } from '@fortawesome/pro-solid-svg-icons/faOctagonXmark';
import { faPen } from '@fortawesome/pro-solid-svg-icons/faPen';
import { faPlay } from '@fortawesome/pro-solid-svg-icons/faPlay';
import { faQrcode } from '@fortawesome/pro-solid-svg-icons/faQrcode';
import { faRightFromBracket } from '@fortawesome/pro-solid-svg-icons/faRightFromBracket';
import { faShieldCheck } from '@fortawesome/pro-solid-svg-icons/faShieldCheck';
import { faShieldExclamation } from '@fortawesome/pro-solid-svg-icons/faShieldExclamation';
import { faShieldHalved } from '@fortawesome/pro-solid-svg-icons/faShieldHalved';
import { faShieldXmark } from '@fortawesome/pro-solid-svg-icons/faShieldXmark';
import { faSidebar } from '@fortawesome/pro-solid-svg-icons/faSidebar';
import { faSidebarFlip } from '@fortawesome/pro-solid-svg-icons/faSidebarFlip';
import { faSort } from '@fortawesome/pro-solid-svg-icons/faSort';
import { faSparkles } from '@fortawesome/pro-solid-svg-icons/faSparkles';
import { faSpinnerThird } from '@fortawesome/pro-solid-svg-icons/faSpinnerThird';
import { faSun } from '@fortawesome/pro-solid-svg-icons/faSun';
import { faTree } from '@fortawesome/pro-solid-svg-icons/faTree';
import { faTriangleExclamation } from '@fortawesome/pro-solid-svg-icons/faTriangleExclamation';
import { faXmark } from '@fortawesome/pro-solid-svg-icons/faXmark';

// Report page icons
import { faPrint } from '@fortawesome/pro-solid-svg-icons/faPrint';
import { faLink } from '@fortawesome/pro-solid-svg-icons/faLink';
import { faCircleQuestion } from '@fortawesome/pro-solid-svg-icons/faCircleQuestion';
import { faChartLine } from '@fortawesome/pro-solid-svg-icons/faChartLine';
import { faThumbsUp } from '@fortawesome/pro-solid-svg-icons/faThumbsUp';
import { faThumbsDown } from '@fortawesome/pro-solid-svg-icons/faThumbsDown';
import { faBinoculars } from '@fortawesome/pro-solid-svg-icons/faBinoculars';
import { faFileLines } from '@fortawesome/pro-solid-svg-icons/faFileLines';

// ── Regular icons (far) ──────────────────────────────────────────────
import { faCopy as farCopy } from '@fortawesome/pro-regular-svg-icons/faCopy';
import { faEye as farEye } from '@fortawesome/pro-regular-svg-icons/faEye';
import { faEyeSlash as farEyeSlash } from '@fortawesome/pro-regular-svg-icons/faEyeSlash';

// ── Register everything ──────────────────────────────────────────────
library.add(
    // Solid
    faAnglesLeft,
    faAnglesRight,
    faArrowDown,
    faArrowLeft,
    faArrowRight,
    faArrowUp,
    faArrowUpRightFromSquare,
    faArrowsRotate,
    faBars,
    faBookOpen,
    faBus,
    faCalendarClock,
    faCartShopping,
    faChartColumn,
    faCheck,
    faChevronDown,
    faChevronLeft,
    faChevronRight,
    faChevronUp,
    faCircle,
    faCircleCheck,
    faCircleExclamation,
    faCircleInfo,
    faCircleXmark,
    faClock,
    faDatabase,
    faDesktop,
    faEllipsis,
    faEnvelope,
    faFloppyDisk,
    faFolder,
    faGear,
    faGraduationCap,
    faGrid2,
    faLocationArrow,
    faLocationDot,
    faLock,
    faLockKeyhole,
    faMagnifyingGlass,
    faMapPin,
    faMinus,
    faMoon,
    faOctagonXmark,
    faPen,
    faPlay,
    faQrcode,
    faRightFromBracket,
    faShieldCheck,
    faShieldExclamation,
    faShieldHalved,
    faShieldXmark,
    faSidebar,
    faSidebarFlip,
    faSort,
    faSparkles,
    faSpinnerThird,
    faSun,
    faTree,
    faTriangleExclamation,
    faXmark,
    // Report
    faPrint,
    faLink,
    faCircleQuestion,
    faChartLine,
    faThumbsUp,
    faThumbsDown,
    faBinoculars,
    faFileLines,
    // Regular
    farCopy,
    farEye,
    farEyeSlash,
);

// Re-export commonly used icons for direct import in components
export {
    faAnglesLeft,
    faAnglesRight,
    faArrowDown,
    faArrowLeft,
    faArrowRight,
    faArrowUp,
    faArrowUpRightFromSquare,
    faArrowsRotate,
    faBars,
    faBookOpen,
    faBus,
    faCalendarClock,
    faCartShopping,
    faChartColumn,
    faCheck,
    faChevronDown,
    faChevronLeft,
    faChevronRight,
    faChevronUp,
    faCircle,
    faCircleCheck,
    faCircleExclamation,
    faCircleInfo,
    faCircleXmark,
    faClock,
    faDatabase,
    faDesktop,
    faEllipsis,
    faEnvelope,
    faFloppyDisk,
    faFolder,
    faGear,
    faGraduationCap,
    faGrid2,
    faLocationArrow,
    faLocationDot,
    faLock,
    faLockKeyhole,
    faMagnifyingGlass,
    faMapPin,
    faMinus,
    faMoon,
    faOctagonXmark,
    faPen,
    faPlay,
    faQrcode,
    faRightFromBracket,
    faShieldCheck,
    faShieldExclamation,
    faShieldHalved,
    faShieldXmark,
    faSidebar,
    faSidebarFlip,
    faSort,
    faSparkles,
    faSpinnerThird,
    faSun,
    faTree,
    faTriangleExclamation,
    faXmark,
    faPrint,
    faLink,
    faCircleQuestion,
    faChartLine,
    faThumbsUp,
    faThumbsDown,
    faBinoculars,
    faFileLines,
    farCopy,
    farEye,
    farEyeSlash,
};
