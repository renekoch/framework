<?php

use Mockery as m;

class SessionStoreTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function testSessionIsLoadedFromHandler()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->with($this->getSessionId())->andReturn(serialize(['foo' => 'bar', 'bagged' => ['name' => 'taylor']]));
        $session->registerBag(new Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag('bagged'));
        $session->start();

        $this->assertEquals('bar', $session->get('foo'));
        $this->assertEquals('baz', $session->get('bar', 'baz'));
        $this->assertTrue($session->has('foo'));
        $this->assertFalse($session->has('bar'));
        $this->assertEquals('taylor', $session->getBag('bagged')->get('name'));
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Session\Storage\MetadataBag', $session->getMetadataBag());
        $this->assertTrue($session->isStarted());

        $session->put('baz', 'boom');
        $this->assertTrue($session->has('baz'));
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSessionGetBagException()
    {
        $session = $this->getSession();
        $session->getBag('doesNotExist');
    }

    public function testSessionMigration()
    {
        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->never();
        $this->assertTrue($session->migrate());
        $this->assertNotEquals($oldId, $session->getId());

        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->once()->with($oldId);
        $this->assertTrue($session->migrate(true));
        $this->assertNotEquals($oldId, $session->getId());
    }

    public function testSessionRegeneration()
    {
        $session = $this->getSession();
        $oldId = $session->getId();
        $session->getHandler()->shouldReceive('destroy')->never();
        $this->assertTrue($session->regenerate());
        $this->assertNotEquals($oldId, $session->getId());
    }

    public function testCantSetInvalidId()
    {
        $session = $this->getSession();
        $this->assertTrue($session->isValidId($session->getId()));

        $session->setId(null);
        $this->assertNotNull($session->getId());
        $this->assertTrue($session->isValidId($session->getId()));

        $session->setId(['a']);
        $this->assertNotSame(['a'], $session->getId());

        $session->setId('wrong');
        $this->assertNotEquals('wrong', $session->getId());
    }

    public function testSessionInvalidate()
    {
        $session = $this->getSession();
        $oldId = $session->getId();

        $session->set('foo', 'bar');
        $this->assertGreaterThan(0, count($session->all()));

        $session->flash('name', 'Taylor');
        $this->assertTrue($session->has('name'));

        $session->getHandler()->shouldReceive('destroy')->once()->with($oldId);
        $this->assertTrue($session->invalidate());

        $this->assertFalse($session->has('name'));
        $this->assertNotEquals($oldId, $session->getId());
        $this->assertCount(0, $session->all());
    }

    public function testSessionIsProperlySaved()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();
        $session->put('foo', 'bar');
        $session->flash('baz', 'boom');
        $session->now('qux', 'norf');
        $session->getHandler()->shouldReceive('write')->once()->with(
            $this->getSessionId(),
            serialize([
                '_token' => $session->token(),
                'foo' => 'bar',
                'baz' => 'boom',
                '_flash' => [
                    'new' => [],
                    'old' => ['baz'],
                ],
                '_sf2_meta' => $session->getBagData('_sf2_meta'),
            ])
        );
        $session->save();

        $this->assertFalse($session->isStarted());
    }

    public function testOldInputFlashing()
    {
        $session = $this->getSession();
        $session->put('boom', 'baz');
        $session->flashInput(['foo' => 'bar', 'bar' => 0]);

        $this->assertTrue($session->hasOldInput('foo'));
        $this->assertEquals('bar', $session->getOldInput('foo'));
        $this->assertEquals(0, $session->getOldInput('bar'));
        $this->assertFalse($session->hasOldInput('boom'));

        $session->ageFlashData();

        $this->assertTrue($session->hasOldInput('foo'));
        $this->assertEquals('bar', $session->getOldInput('foo'));
        $this->assertEquals(0, $session->getOldInput('bar'));
        $this->assertFalse($session->hasOldInput('boom'));
    }

    public function testDataFlashing()
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->flash('bar', 0);

        $this->assertTrue($session->has('foo'));
        $this->assertEquals('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));

        $session->ageFlashData();

        $this->assertTrue($session->has('foo'));
        $this->assertEquals('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));

        $session->ageFlashData();

        $this->assertFalse($session->has('foo'));
        $this->assertNull($session->get('foo'));
    }

    public function testDataFlashingNow()
    {
        $session = $this->getSession();
        $session->now('foo', 'bar');
        $session->now('bar', 0);

        $this->assertTrue($session->has('foo'));
        $this->assertEquals('bar', $session->get('foo'));
        $this->assertEquals(0, $session->get('bar'));

        $session->ageFlashData();

        $this->assertFalse($session->has('foo'));
        $this->assertNull($session->get('foo'));
    }

    public function testDataMergeNewFlashes()
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->set('fu', 'baz');
        $session->set('_flash.old', ['qu']);
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('fu', $session->get('_flash.new')));
        $session->keep(['fu', 'qu']);
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertNotFalse(array_search('fu', $session->get('_flash.new')));
        $this->assertNotFalse(array_search('qu', $session->get('_flash.new')));
        $this->assertFalse(array_search('qu', $session->get('_flash.old')));
    }

    public function testReflash()
    {
        $session = $this->getSession();
        $session->flash('foo', 'bar');
        $session->set('_flash.old', ['foo']);
        $session->reflash();
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('foo', $session->get('_flash.old')));
    }

    public function testReflashWithNow()
    {
        $session = $this->getSession();
        $session->now('foo', 'bar');
        $session->reflash();
        $this->assertNotFalse(array_search('foo', $session->get('_flash.new')));
        $this->assertFalse(array_search('foo', $session->get('_flash.old')));
    }

    public function testReplace()
    {
        $session = $this->getSession();
        $session->set('foo', 'bar');
        $session->set('qu', 'ux');
        $session->replace(['foo' => 'baz']);
        $this->assertEquals('baz', $session->get('foo'));
        $this->assertEquals('ux', $session->get('qu'));
    }

    public function testRemove()
    {
        $session = $this->getSession();
        $session->set('foo', 'bar');
        $pulled = $session->remove('foo');
        $this->assertFalse($session->has('foo'));
        $this->assertEquals('bar', $pulled);
    }

    public function testClear()
    {
        $session = $this->getSession();
        $session->set('foo', 'bar');

        $bag = new Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag('bagged');
        $bag->set('qu', 'ux');
        $session->registerBag($bag);

        $session->clear();
        $this->assertFalse($session->has('foo'));
        $this->assertFalse($session->getBag('bagged')->has('qu'));

        $session->set('foo', 'bar');
        $session->getBag('bagged')->set('qu', 'ux');

        $session->flush();
        $this->assertFalse($session->has('foo'));
        $this->assertFalse($session->getBag('bagged')->has('qu'));
    }

    public function testIncrement()
    {
        $session = $this->getSession();

        $session->set('foo', 5);
        $foo = $session->increment('foo');
        $this->assertEquals(6, $foo);
        $this->assertEquals(6, $session->get('foo'));

        $foo = $session->increment('foo', 4);
        $this->assertEquals(10, $foo);
        $this->assertEquals(10, $session->get('foo'));

        $session->increment('bar');
        $this->assertEquals(1, $session->get('bar'));
    }

    public function testDecrement()
    {
        $session = $this->getSession();

        $session->set('foo', 5);
        $foo = $session->decrement('foo');
        $this->assertEquals(4, $foo);
        $this->assertEquals(4, $session->get('foo'));

        $foo = $session->decrement('foo', 4);
        $this->assertEquals(0, $foo);
        $this->assertEquals(0, $session->get('foo'));

        $session->decrement('bar');
        $this->assertEquals(-1, $session->get('bar'));
    }

    public function testHasOldInputWithoutKey()
    {
        $session = $this->getSession();
        $session->flash('boom', 'baz');
        $this->assertFalse($session->hasOldInput());

        $session->flashInput(['foo' => 'bar']);
        $this->assertTrue($session->hasOldInput());
    }

    public function testHandlerNeedsRequest()
    {
        $session = $this->getSession();
        $this->assertFalse($session->handlerNeedsRequest());
        $session->getHandler()->shouldReceive('setRequest')->never();

        $session = new Illuminate\Session\Store('test', m::mock(new Illuminate\Session\CookieSessionHandler(new Illuminate\Cookie\CookieJar(), 60)));
        $this->assertTrue($session->handlerNeedsRequest());
        $session->getHandler()->shouldReceive('setRequest')->once();
        $request = new Symfony\Component\HttpFoundation\Request();
        $session->setRequestOnHandler($request);
    }

    public function testToken()
    {
        $session = $this->getSession();
        $this->assertEquals($session->token(), $session->getToken());
    }

    public function testRegenerateToken()
    {
        $session = $this->getSession();
        $token = $session->getToken();
        $session->regenerateToken();
        $this->assertNotEquals($token, $session->getToken());
    }

    public function testName()
    {
        $session = $this->getSession();
        $this->assertEquals($session->getName(), $this->getSessionName());
        $session->setName('foo');
        $this->assertEquals($session->getName(), 'foo');
    }

    public function testKeyExists()
    {
        $session = $this->getSession();
        $session->set('foo', 'bar');
        $this->assertTrue($session->exists('foo'));
        $session->set('baz', null);
        $this->assertFalse($session->has('baz'));
        $this->assertTrue($session->exists('baz'));
        $this->assertFalse($session->exists('bogus'));
        $this->assertTrue($session->exists(['foo', 'baz']));
        $this->assertFalse($session->exists(['foo', 'baz', 'bogus']));
    }

    public function testRememberMethodCallsPutAndReturnsDefault()
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('get')->andReturn(null);
        $result = $session->remember('foo', function () {
            return 'bar';
        });
        $this->assertEquals('bar', $session->get('foo'));
        $this->assertEquals('bar', $result);
    }

    public function getSession()
    {
        $reflection = new ReflectionClass('Illuminate\Session\Store');

        return $reflection->newInstanceArgs($this->getMocks());
    }

    public function getMocks()
    {
        return [
            $this->getSessionName(),
            m::mock('SessionHandlerInterface'),
            $this->getSessionId(),
        ];
    }

    public function getSessionId()
    {
        return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }

    public function getSessionName()
    {
        return 'name';
    }
}
