import type { CSSProperties } from 'react';
import type { EquipmentCategory } from '@/types';

/** Fill and border colour per category, readable in light and dark mode. */
const colors: Record<EquipmentCategory, { fill: string; border: string }> = {
    camera: { fill: 'rgb(14 165 233 / 0.22)', border: 'rgb(2 132 199)' },
    lighting: { fill: 'rgb(245 158 11 / 0.25)', border: 'rgb(217 119 6)' },
    space: { fill: 'rgb(139 92 246 / 0.22)', border: 'rgb(124 58 237)' },
};

/**
 * Block colours: the first item's category, striped when the booking mixes
 * categories. The user's own bookings get a solid border; others a lighter
 * fill and a faint border.
 */
export function blockStyle(
    categories: EquipmentCategory[],
    mine: boolean,
): CSSProperties {
    const unique = [...new Set(categories)];
    const first = colors[unique[0] ?? 'camera'];
    const background =
        unique.length > 1
            ? {
                  backgroundImage: `repeating-linear-gradient(135deg, ${unique
                      .map(
                          (category, index) =>
                              `${colors[category].fill} ${index * 10}px ${(index + 1) * 10}px`,
                      )
                      .join(', ')})`,
              }
            : { backgroundColor: first.fill };

    return {
        ...background,
        borderColor: first.border,
        borderStyle: 'solid',
        borderWidth: mine ? 2 : 1,
        opacity: mine ? 1 : 0.85,
    };
}
