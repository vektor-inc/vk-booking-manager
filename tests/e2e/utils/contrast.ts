import type { Locator } from '@playwright/test';

/**
 * 実際の背景と文字のコントラスト比を測る（透明背景は祖先へたどる）。
 *
 * @param locator 文字要素。
 */
export async function getTextContrast( locator: Locator ): Promise< number > {
	return locator.evaluate( ( element ) => {
		// Canvas の sRGB 出力を使い、oklch / color() も同じ色空間へ変換する。
		const canvas = document.createElement( 'canvas' );
		canvas.width = canvas.height = 1;
		const context = canvas.getContext( '2d', { colorSpace: 'srgb' } )!;
		const rgba = ( color: string ) => {
			context.clearRect( 0, 0, 1, 1 );
			// 不正な色は代入が無視されるため、異なる初期色から同じ値になるか確かめる。
			context.fillStyle = '#000';
			context.fillStyle = color;
			const fromBlack = context.fillStyle;
			context.fillStyle = '#fff';
			context.fillStyle = color;
			if ( context.fillStyle !== fromBlack ) {
				throw new Error( `読めない色です: ${ color }` );
			}
			context.fillRect( 0, 0, 1, 1 );
			return Array.from( context.getImageData( 0, 0, 1, 1 ).data ).map(
				( value ) => value / 255
			);
		};
		const over = ( front: number[], back: number[] ) => [
			...front
				.slice( 0, 3 )
				.map(
					( value, i ) =>
						value * front[ 3 ] + back[ i ] * ( 1 - front[ 3 ] )
				),
			1,
		];
		// 外側から順に重ね、半透明の祖先も最終的な背景色へ合成する。
		const backgrounds: number[][] = [];
		for (
			let node: Element | null = element;
			node;
			node = node.parentElement
		) {
			backgrounds.push(
				rgba( getComputedStyle( node ).backgroundColor )
			);
		}
		const background = backgrounds
			.reverse()
			.reduce( ( back, front ) => over( front, back ), [ 1, 1, 1, 1 ] );
		const foreground = over(
			rgba( getComputedStyle( element ).color ),
			background
		);
		const luminance = ( color: number[] ) =>
			color
				.slice( 0, 3 )
				.reduce(
					( sum, channel, i ) =>
						sum +
						[ 0.2126, 0.7152, 0.0722 ][ i ] *
							( channel <= 0.04045
								? channel / 12.92
								: ( ( channel + 0.055 ) / 1.055 ) ** 2.4 ),
					0
				);
		const a = luminance( foreground );
		const b = luminance( background );
		return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
	} );
}
