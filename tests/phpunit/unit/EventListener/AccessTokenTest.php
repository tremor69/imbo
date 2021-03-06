<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace ImboUnitTest\EventListener;

use Imbo\EventListener\AccessToken,
    Imbo\Exception\ConfigurationException;

/**
 * @covers Imbo\EventListener\AccessToken
 * @group unit
 * @group listeners
 */
class AccessTokenTest extends ListenerTests {
    /**
     * @var AccessToken
     */
    private $listener;

    private $event;
    private $accessControl;
    private $request;
    private $response;
    private $responseHeaders;
    private $query;

    /**
     * Set up the listener
     */
    public function setUp() {
        $this->query = $this->createMock('Symfony\Component\HttpFoundation\ParameterBag');

        $this->accessControl = $this->createMock('Imbo\Auth\AccessControl\Adapter\AdapterInterface');

        $this->request = $this->createMock('Imbo\Http\Request\Request');
        $this->request->query = $this->query;

        $this->responseHeaders = $this->createMock('Symfony\Component\HttpFoundation\ResponseHeaderBag');
        $this->response = $this->createMock('Imbo\Http\Response\Response');
        $this->response->headers = $this->responseHeaders;

        $this->event = $this->getEventMock();

        $this->listener = new AccessToken();
    }

    /**
     * {@inheritdoc}
     */
    protected function getListener() {
        return $this->listener;
    }

    /**
     * Tear down the listener
     */
    public function tearDown() {
        $this->query = null;
        $this->request = null;
        $this->response = null;
        $this->responseHeaders = null;
        $this->event = null;
        $this->listener = null;
    }

    /**
     * @expectedException Imbo\Exception\RuntimeException
     * @expectedExceptionMessage Incorrect access token
     * @expectedExceptionCode 400
     * @covers Imbo\EventListener\AccessToken::checkAccessToken
     */
    public function testRequestWithBogusAccessToken() {
        $this->query->expects($this->once())->method('has')->with('accessToken')->will($this->returnValue(true));
        $this->query->expects($this->once())->method('get')->with('accessToken')->will($this->returnValue('/string'));
        $this->listener->checkAccessToken($this->event);
    }

    /**
     * @expectedException Imbo\Exception\RuntimeException
     * @expectedExceptionMessage Missing access token
     * @expectedExceptionCode 400
     * @covers Imbo\EventListener\AccessToken::checkAccessToken
     * @covers Imbo\EventListener\AccessToken::isWhitelisted
     */
    public function testThrowsExceptionIfAnAccessTokenIsMissingFromTheRequestWhenNotWhitelisted() {
        $this->event->expects($this->once())->method('getName')->will($this->returnValue('image.get'));
        $this->query->expects($this->once())->method('has')->with('accessToken')->will($this->returnValue(false));

        $this->listener->checkAccessToken($this->event);
    }

    /**
     * Different filter combinations
     *
     * @return array[]
     */
    public function getFilterData() {
        return [
            'no filter and no transformations' => [
                $filter = [],
                $transformations = [],
                $whitelisted = false,
            ],
            // @see https://github.com/imbo/imbo/issues/258
            'configured filters, but no transformations in the request' => [
                $filter = ['transformations' => ['whitelist' => ['border']]],
                $transformations = [],
                $whitelisted = false,
            ],
            [
                $filter = [],
                $transformations = [
                    ['name' => 'border', 'params' => []],
                ],
                $whitelisted = false,
            ],
            [
                $filter = ['transformations' => ['whitelist' => ['border']]],
                $transformations = [
                    ['name' => 'border', 'params' => []],
                ],
                $whitelisted = true,
            ],
            [
                $filter = ['transformations' => ['whitelist' => ['border']]],
                $transformations = [
                    ['name' => 'border', 'params' => []],
                    ['name' => 'thumbnail', 'params' => []],
                ],
                $whitelisted = false,
            ],
            [
                $filter = ['transformations' => ['blacklist' => ['border']]],
                $transformations = [
                    ['name' => 'thumbnail', 'params' => []],
                ],
                $whitelisted = true,
            ],
            [
                $filter = ['transformations' => ['blacklist' => ['border']]],
                $transformations = [
                    ['name' => 'border', 'params' => []],
                    ['name' => 'thumbnail', 'params' => []],
                ],
                $whitelisted = false,
            ],
            [
                $filter = ['transformations' => ['whitelist' => ['border'], 'blacklist' => ['thumbnail']]],
                $transformations = [
                    ['name' => 'border', 'params' => []],
                ],
                $whitelisted = true,
            ],
            [
                $filter = ['transformations' => ['whitelist' => ['border'], 'blacklist' => ['thumbnail']]],
                $transformations = [
                    ['name' => 'canvas', 'params' => []],
                ],
                $whitelisted = false,
            ],
            [
                $filter = ['transformations' => ['whitelist' => ['border'], 'blacklist' => ['border']]],
                $transformations = [
                    ['name' => 'border', 'params' => []],
                ],
                $whitelisted = false,
            ],
        ];
    }

    /**
     * @dataProvider getFilterData
     * @covers Imbo\EventListener\AccessToken::__construct
     * @covers Imbo\EventListener\AccessToken::checkAccessToken
     * @covers Imbo\EventListener\AccessToken::isWhitelisted
     */
    public function testSupportsFilters($filter, $transformations, $whitelisted) {
        $listener = new AccessToken($filter);

        if (!$whitelisted) {
            $this->expectException('Imbo\Exception\RuntimeException', 'Missing access token', 400);
        }

        $this->event->expects($this->once())->method('getName')->will($this->returnValue('image.get'));
        $this->request->expects($this->any())->method('getTransformations')->will($this->returnValue($transformations));

        $listener->checkAccessToken($this->event);
    }

    /**
     * Get access tokens
     *
     * @return array[]
     */
    public function getAccessTokens() {
        return [
            [
                'http://imbo/users/christer',
                'some access token',
                'private key',
                false
            ],
            [
                'http://imbo/users/christer',
                '81b52f01115401e5bcd0b65b625258510f8823e0b3189c13d279f84c4eb0ac3a',
                'private key',
                true
            ],
            [
                'http://imbo/users/christer',
                '81b52f01115401e5bcd0b65b625258510f8823e0b3189c13d279f84c4eb0ac3a',
                'other private key',
                false
            ],
            // Test that checking URL "as is" works properly. This is for backwards compatibility.
            [
                'http://imbo/users/christer?t[]=thumbnail%3Awidth%3D40%2Cheight%3D40%2Cfit%3Doutbound',
                'f0166cb4f7c8eabbe82c5d753f681ed53bcfa10391d4966afcfff5806cc2bff4',
                'some random private key',
                true
            ],
            // Test checking URL against incorrect protocol
            [
                'https://imbo/users/christer',
                '81b52f01115401e5bcd0b65b625258510f8823e0b3189c13d279f84c4eb0ac3a',
                'private key',
                false
            ],
            // Test that URLs with t[0] and t[] can use the same accessToken
            [
                'http://imbo/users/imbo/images/foobar?t%5B0%5D=maxSize%3Awidth%3D400%2Cheight%3D400&t%5B1%5D=crop%3Ax%3D50%2Cy%3D50%2Cwidth%3D50%2Cheight%3D50',
                '1ae7643a70e68377502c30ba54d0ffbfedd67a1f3c4b3f038a42c0ed17ad3551',
                'foo',
                true
            ],
            [
                'http://imbo/users/imbo/images/foobar?t%5B%5D=maxSize%3Awidth%3D400%2Cheight%3D400&t%5B%5D=crop%3Ax%3D50%2Cy%3D50%2Cwidth%3D50%2Cheight%3D50',
                '1ae7643a70e68377502c30ba54d0ffbfedd67a1f3c4b3f038a42c0ed17ad3551',
                'foo',
                true
            ],
            // and that they still break if something else is changed
            [
                'http://imbo/users/imbo/images/foobar?g%5B%5D=maxSize%3Awidth%3D400%2Cheight%3D400&t%5B%5D=crop%3Ax%3D50%2Cy%3D50%2Cwidth%3D50%2Cheight%3D50',
                '1ae7643a70e68377502c30ba54d0ffbfedd67a1f3c4b3f038a42c0ed17ad3551',
                'foo',
                false
            ],
        ];
    }

    /**
     * @dataProvider getAccessTokens
     * @covers Imbo\EventListener\AccessToken::checkAccessToken
     */
    public function testThrowsExceptionOnIncorrectToken($url, $token, $privateKey, $correct) {
        if (!$correct) {
            $this->expectException('Imbo\Exception\RuntimeException', 'Incorrect access token', 400);
        }

        $this->query->expects($this->once())->method('has')->with('accessToken')->will($this->returnValue(true));
        $this->query->expects($this->once())->method('get')->with('accessToken')->will($this->returnValue($token));
        $this->request->expects($this->atLeastOnce())->method('getRawUri')->will($this->returnValue(urldecode($url)));
        $this->request->expects($this->atLeastOnce())->method('getUriAsIs')->will($this->returnValue($url));

        $this->accessControl->expects($this->once())->method('getPrivateKey')->will($this->returnValue($privateKey));

        $this->listener->checkAccessToken($this->event);
    }

    /**
     * @covers Imbo\EventListener\AccessToken::checkAccessToken
     */
    public function testWillSkipValidationWhenShortUrlHeaderIsPresent() {
        $this->responseHeaders->expects($this->once())->method('has')->with('X-Imbo-ShortUrl')->will($this->returnValue(true));
        $this->query->expects($this->never())->method('has');
        $this->listener->checkAccessToken($this->event);
    }

    /**
     * @covers Imbo\EventListener\AccessToken::checkAccessToken
     */
    public function testWillAcceptBothProtocolsIfConfiguredTo() {
        $event = $this->getEventMock([
            'authentication' => [
                'protocol' => 'both'
            ]
        ]);

        $privateKey = 'foobar';
        $baseUrl = '//imbo.host/users/some-user/imgUrl.png?t[]=smartSize:width=320,height=240';

        foreach (['http', 'https'] as $signedProtocol) {
            $token = hash_hmac('sha256', $signedProtocol . ':' . $baseUrl, $privateKey);

            foreach (['http', 'https'] as $protocol) {
                $url = $protocol . ':' . $baseUrl . '&accessToken=' . $token;

                $this->query->expects($this->any())->method('has')->with('accessToken')->will($this->returnValue(true));
                $this->query->expects($this->any())->method('get')->with('accessToken')->will($this->returnValue($token));
                $this->request->expects($this->any())->method('getRawUri')->will($this->returnValue(urldecode($url)));
                $this->request->expects($this->any())->method('getUriAsIs')->will($this->returnValue($url));

                $this->accessControl->expects($this->any())->method('getPrivateKey')->will($this->returnValue($privateKey));

                $this->listener->checkAccessToken($event);
            }
        }
    }

    /**
     * Test that we can configure the access token argument key
     */
    public function testAccessTokenArgumentKey() {
        $url = 'http://imbo/users/christer';
        $token = '81b52f01115401e5bcd0b65b625258510f8823e0b3189c13d279f84c4eb0ac3a';
        $privateKey = 'private key';

        $listener = new AccessToken([
            'accessTokenGenerator' => new AccessToken\SHA256(['argumentKeys' => ['foo']]),
        ]);

        $this->query->expects($this->once())->method('has')->with('foo')->will($this->returnValue(true));
        $this->query->expects($this->once())->method('get')->with('foo')->will($this->returnValue($token));
        $this->request->expects($this->atLeastOnce())->method('getRawUri')->will($this->returnValue(urldecode($url)));
        $this->request->expects($this->atLeastOnce())->method('getUriAsIs')->will($this->returnValue($url));

        $this->accessControl->expects($this->once())->method('getPrivateKey')->will($this->returnValue($privateKey));
        $listener->checkAccessToken($this->event);
    }

    public function getAccessTokensForMultipleGenerator() {
        $tokens = array();

        foreach ($this->getAccessTokens() as $token) {
            $token[] = 'accessToken';
            $tokens[] = $token;
        }

        $tokens = array_merge($tokens, [
            [
                'http://imbo/users/imbo/images/foobar?t%5B%5D=maxSize%3Awidth%3D400%2Cheight%3D400&t%5B%5D=crop%3Ax%3D50%2Cy%3D50%2Cwidth%3D50%2Cheight%3D50',
                'dummy',
                'foo',
                true,
                'dummy'
            ],
            [
                'http://imbo/users/imbo/images/foobar?t%5B%5D=maxSize%3Awidth%3D400%2Cheight%3D400&t%5B%5D=crop%3Ax%3D50%2Cy%3D50%2Cwidth%3D50%2Cheight%3D50123',
                'dummy',
                'foobar',
                true,
                'dummy'
            ],
            [
                'http://imbo/users/imbo/images/foobar?t%5B%5D=maxSize%3Awidth%3D400%2Cheight%3D400&t%5B%5D=crop%3Ax%3D50%2Cy%3D50%2Cwidth%3D50%2Cheight%3D50123',
                'boop',
                'foobar',
                false,
                'dummy'
            ],
        ]);

        return $tokens;
    }

    /**
     * Test using the multiple access token generators generator
     *
     * @dataProvider getAccessTokensForMultipleGenerator
     */
    public function testMultipleAccessTokensGenerator($url, $token, $privateKey, $correct, $argumentKey) {
        if (!$correct) {
            $this->expectException('Imbo\Exception\RuntimeException', 'Incorrect access token', 400);
        }

        $dummyAccessToken = $this->createMock('Imbo\EventListener\AccessToken\AccessTokenInterface');
        $dummyAccessToken->expects($this->any())->method('generateSignature')->will($this->returnValue('dummy'));

        $listener = new AccessToken([
            'accessTokenGenerator' => new AccessToken\MultipleAccessTokenGenerators([
                    'generators' => [
                        'accessToken' => new AccessToken\SHA256(),
                        'dummy' => $dummyAccessToken,
                    ]
                ]
            ),
        ]);

        // Allows us to return 'false' as default if the key isn't present
        $this->query->expects($this->atLeastOnce())->method('has')->with($this->logicalOr(
            $this->equalTo($argumentKey),
            $this->anything()
        ))->will($this->returnCallback(function ($val) use ($argumentKey) { return $val == $argumentKey; }));
        $this->query->expects($this->atLeastOnce())->method('get')->with($argumentKey)->will($this->returnValue($token));
        $this->request->expects($this->atLeastOnce())->method('getRawUri')->will($this->returnValue(urldecode($url)));
        $this->request->expects($this->atLeastOnce())->method('getUriAsIs')->will($this->returnValue($url));

        $this->accessControl->expects($this->once())->method('getPrivateKey')->will($this->returnValue($privateKey));

        $listener->checkAccessToken($this->event);
    }

    /**
     * Test that we can configure the access token argument key
     *
     * @expectedException Imbo\Exception\ConfigurationException
     * @expectedExceptionMessage Invalid accessTokenGenerator
     * @expectedExceptionCode 500
     */
    public function testConfigurationExceptionOnInvalidAccessTokenGenerator() {
        $listener = new AccessToken([
            'accessTokenGenerator' => new \StdClass(),
        ]);

        $listener->checkAccessToken($this->event);
    }

    /**
     * Get access tokens with rewritten URLs
     *
     * @return array[]
     */
    public function getRewrittenAccessTokenData() {
        $getAccessToken = function($url) {
            return hash_hmac('sha256', $url, 'foobar');
        };

        return [
            [
                // Access token created from URL
                $getAccessToken('http://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240'),
                 // URL returned by Imbo
                'http://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240',
                // Protocol which Imbo is configured to rewrite incoming URL to
                'http',
                // Should it accept the access token as correct?
                true
            ],
            [
                $getAccessToken('http://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240'),
                'https://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240',
                'http',
                true
            ],
            [
                $getAccessToken('https://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240'),
                'https://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240',
                'http',
                false
            ],
            [
                $getAccessToken('https://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240'),
                'http://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240',
                'http',
                false
            ],
            [
                $getAccessToken('https://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240'),
                'http://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240',
                'https',
                true
            ],
            [
                $getAccessToken('https://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240'),
                'https://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240',
                'https',
                true
            ],
            [
                $getAccessToken('http://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240'),
                'https://imbo/users/espen/img.png?t[]=smartSize:width=320,height=240',
                'https',
                false
            ],
        ];
    }

    /**
     * @dataProvider getRewrittenAccessTokenData
     * @covers Imbo\EventListener\AccessToken::checkAccessToken
     */
    public function testWillRewriteIncomingUrlToConfiguredProtocol($accessToken, $url, $protocol, $correct) {
        if (!$correct) {
            $this->expectException('Imbo\Exception\RuntimeException', 'Incorrect access token', 400);
        }

        $event = $this->getEventMock([
            'authentication' => [
                'protocol' => $protocol
            ]
        ]);

        $url = $url . '&accessToken=' . $accessToken;

        $this->query->expects($this->any())->method('has')->with('accessToken')->will($this->returnValue(true));
        $this->query->expects($this->any())->method('get')->with('accessToken')->will($this->returnValue($accessToken));
        $this->request->expects($this->any())->method('getRawUri')->will($this->returnValue(urldecode($url)));
        $this->request->expects($this->any())->method('getUriAsIs')->will($this->returnValue($url));

        $this->accessControl->expects($this->any())->method('getPrivateKey')->will($this->returnValue('foobar'));

        $this->listener->checkAccessToken($event);
    }

    protected function getEventMock($config = null) {
        $event = $this->createMock('Imbo\EventManager\Event');
        $event->expects($this->any())->method('getAccessControl')->will($this->returnValue($this->accessControl));
        $event->expects($this->any())->method('getRequest')->will($this->returnValue($this->request));
        $event->expects($this->any())->method('getResponse')->will($this->returnValue($this->response));
        $event->expects($this->any())->method('getConfig')->will($this->returnValue($config ?: [
            'authentication' => [
                'protocol' => 'incoming'
            ]
        ]));
        return $event;
    }
}