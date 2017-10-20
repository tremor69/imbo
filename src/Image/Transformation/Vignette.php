<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace Imbo\Image\Transformation;

use Imbo\Image\Transformation\Transformation,
    Imbo\Exception\TransformationException,
    Imagick,
    ImagickException;

/**
 * Vignette transformation
 *
 * @author Espen Hovlandsdal <espen@hovlandsdal.com>
 * @package Image\Transformations
 */
class Vignette extends Transformation {
    /**
     * {@inheritdoc}
     */
    public function transform(array $params) {
        $inner  = $this->formatColor(isset($params['inner']) ? $params['inner'] : 'none');
        $outer  = $this->formatColor(isset($params['outer']) ? $params['outer'] : '000');
        $scale  = (float) max(isset($params['scale']) ? $params['scale'] : 1.5, 1);

        $image  = $this->image;
        $width  = $image->getWidth();
        $height = $image->getHeight();

        $scaleX = floor($width  * $scale);
        $scaleY = floor($height * $scale);

        $vignette = new Imagick();
        $vignette->newPseudoImage($scaleX, $scaleY, 'radial-gradient:' . $inner . '-' . $outer);
        $vignette->cropImage(
            $width,
            $height,
            floor(($scaleX - $width)  / 2),
            floor(($scaleY - $height) / 2)
        );

        try {
            $this->imagick->compositeImage($vignette, Imagick::COMPOSITE_MULTIPLY, 0, 0);
        } catch (ImagickException $e) {
            throw new TransformationException($e->getMessage(), 400, $e);
        }

        $image->hasBeenTransformed(true);
    }
}
