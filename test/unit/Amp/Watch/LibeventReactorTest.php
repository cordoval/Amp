<?php

use Amp\Watch\LibeventReactor;

class LibeventReactorTest extends PHPUnit_Framework_TestCase {
    
    private function skipIfMissingExtLibevent() {
        if (!extension_loaded('libevent')) {
            $this->markTestSkipped(
                'libevent extension not available'
            );
        }
    }
    
    function testEnablingSubscriptionAllowsSubsequentInvocation() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        $testIncrement = 0;
        
        $subscription = $reactor->once(function() use (&$testIncrement) {
            $testIncrement++;
        }, $delay = 0);
        
        $subscription->disable();
        
        $reactor->once(function() use ($reactor) {
            $reactor->stop();
        }, $delay = 0.01);
        
        $reactor->run();
        $this->assertEquals(0, $testIncrement);
        
        $subscription->enable();
        $reactor->once(function() use ($reactor) {
            $reactor->stop();
        }, $delay = 0.01);
        
        $reactor->run();
        $this->assertEquals(1, $testIncrement);
    }
    
    function testDisablingSubscriptionPreventsSubsequentInvocation() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        $testIncrement = 0;
        
        $subscription = $reactor->once(function() use (&$testIncrement) {
            $testIncrement++;
        }, $delay = 0);
        
        $subscription->disable();
        
        $reactor->once(function() use ($reactor) {
            $reactor->stop();
        }, $delay = 0.01);
        
        $reactor->run();
        $this->assertEquals(0, $testIncrement);
    }
    
    function testUnresolvedEventsAreReenabledOnRunFollowingPreviousStop() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        $testIncrement = 0;
        
        $reactor->once(function() use (&$testIncrement, $reactor) {
            $testIncrement++;
            $reactor->stop();
        }, $delay = 0.1);
        
        $reactor->immediately(function() use ($reactor) {
            $reactor->stop();
        });
        
        $reactor->run();
        $this->assertEquals(0, $testIncrement);
        usleep(150000);
        $reactor->run();
        $this->assertEquals(1, $testIncrement);
    }
    
    function testImmediateExecution() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $testIncrement = 0;
        
        $reactor->immediately(function() use (&$testIncrement) {
            $testIncrement++;
        });
        $reactor->tick();
        
        $this->assertEquals(1, $testIncrement);
    }
    
    function testTickExecutesReadyEvents() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $testIncrement = 0;
        
        $reactor->once(function() use (&$testIncrement) {
            $testIncrement++;
        });
        $reactor->tick();
        
        $this->assertEquals(1, $testIncrement);
    }
    
    function testRunExecutesEventsUntilExplicitlyStopped() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $testIncrement = 0;
        
        $reactor->schedule(function() use (&$testIncrement, $reactor) {
            if ($testIncrement < 10) {
                $testIncrement++;
            } else {
                $reactor->stop();
            }
        }, $delay = 0.001);
        $reactor->run();
        
        $this->assertEquals(10, $testIncrement);
    }
    
    function testOnceReturnsEventSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $subscription = $reactor->once(function(){});
        
        $this->assertInstanceOf('Amp\Watch\Subscription', $subscription);
    }
    
    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage test
     */
    function testReactorAllowsExceptionToBubbleUpDuringTick() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        $reactor->once(function(){ throw new RuntimeException('test'); });
        $reactor->tick();
    }
    
    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage test
     */
    function testReactorAllowsExceptionToBubbleUpDuringRun() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        $reactor->once(function(){ throw new RuntimeException('test'); });
        $reactor->run();
    }
    
    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage test
     */
    function testReactorAllowsExceptionToBubbleUpFromRepeatingAlarmDuringRun() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        $reactor->schedule(function(){ throw new RuntimeException('test'); });
        $reactor->run();
    }
    
    function testRepeatReturnsEventSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $subscription = $reactor->schedule(function(){}, $interval = 1);
        
        $this->assertInstanceOf('Amp\Watch\Subscription', $subscription);
    }
    
    function testCancelRemovesSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $subscription = $reactor->once(function(){
            $this->fail('Subscription was not cancelled as expected');
        }, $delay = 0.001);
        
        $reactor->once(function() use ($subscription) { $subscription->cancel(); });
        $reactor->once(function() use ($reactor) { $reactor->stop(); }, $delay = 0.002);
        $reactor->run();
    }
    
    function testRepeatCancelsSubscriptionAfterSpecifiedNumberOfIterations() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $counter = 0;
        
        $reactor->schedule(function() use (&$counter) { ++$counter; }, $delay = 0, $iterations = 3);
        $reactor->once(function() use ($reactor, $counter) { $reactor->stop(); }, $delay = 0.005);
        
        $reactor->run();
        $this->assertEquals(3, $counter);
    }
    
    function testOnWritableSubscription() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $flag = FALSE;
        
        $reactor->onWritable(STDOUT, function() use ($reactor, &$flag) {
            $flag = TRUE;
            $reactor->stop();
        });
        $reactor->once(function() use ($reactor) { $reactor->stop(); }, 0.05);
        
        $reactor->run();
        $this->assertTrue($flag);
    }
    
    /**
     * @expectedException RuntimeException
     */
    function testDescriptorSubscriptionCallbackDoesntSwallowExceptions() {
        $this->skipIfMissingExtLibevent();
        $reactor = new LibeventReactor;
        
        $reactor->onWritable(STDOUT, function() { throw new RuntimeException; });
        $reactor->once(function() use ($reactor) { $reactor->stop(); }, 0.05);
        $reactor->run();
    }
    
    function testGarbageCollection() {
        $this->skipIfMissingExtLibevent();
        
        $reactor = new LibeventReactor();
        $reactor->once(function() use ($reactor) { $reactor->stop(); }, 0.8);
        $reactor->run();
    }
    
}