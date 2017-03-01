<?php namespace Royalcms\Component\Session;

use Closure;
use SessionHandlerInterface;
use Royalcms\Component\DateTime\Carbon;
use Symfony\Component\HttpFoundation\Request;
use Royalcms\Component\Support\Facades\Hook as RC_Hook;

class StartSession {
    
    /**
     * The session manager.
     *
     * @var \Royalcms\Component\Session\SessionManager
     */
    protected $manager;
    
    /**
     * The session store.
     *
     * @var \Royalcms\Component\Session\SessionManager
     */
    protected $session;
    
    /**
     * Create a new session middleware.
     *
     * @param  \Symfony\Component\HttpKernel\HttpKernelInterface  $royalcms
     * @param  \Royalcms\Component\Session\SessionManager  $manager
     * @param  \Closure|null  $reject
     * @return void
     */
    public function __construct(SessionManager $manager)
    {
        $this->manager = $manager;
    }
    
    public function start(Request $request) 
    {
        
        // If a session driver has been configured, we will need to start the session here
        // so that the data is ready for an application. Note that the Laravel sessions
        // do not make use of PHP "native" sessions in any way since they are crappy.
        if ($this->sessionConfigured())
        {
            $this->session = $session = $this->startSession($request);
        
            $request->setSession($session);
        }
        
    }

    public function close() 
    {
        // Again, if the session has been configured we will need to close out the session
        // so that the attributes may be persisted to some storage medium. We will also
        // add the session identifier cookie to the application response headers now.
        if ($this->sessionConfigured())
        {
            $this->closeSession($this->session);
        }
    }
    
    /**
     * PHP Native Session
     * @param \SessionHandlerInterface $handler
     */
    protected function setNativeSessionHandler(SessionHandlerInterface $handler) {
        /*
         * @todo 部分windows系统下，使用PHP5.4 SessionHandlerInterface接口会出现死循环，暂时未查出原因
        if (version_compare(PHP_VERSION, '5.4.0', '<'))
        {
            session_set_save_handler(
                array(& $handler, 'open'),
                array(& $handler, 'close'),
                array(& $handler, 'read'),
                array(& $handler, 'write'),
                array(& $handler, 'destroy'),
                array(& $handler, 'gc')
            );
        }
        else
        {
            session_set_save_handler($handler, true);
        }
        */
        
        ini_set('session.save_handler', 'user');
            
        session_set_save_handler(
            array(& $handler, 'open'),
            array(& $handler, 'close'),
            array(& $handler, 'read'),
            array(& $handler, 'write'),
            array(& $handler, 'destroy'),
            array(& $handler, 'gc')
        );
        
        register_shutdown_function('session_write_close');
    }
    
    /**
     * Start the session for the given request.
     *
     * @param  \Symfony\Component\HttpFoundation\Request  $request
     * @return \Royalcms\Component\Session\SessionInterface
     */
    protected function startSession(Request $request)
    {
        with($session = $this->getSession($request))->setRequestOnHandler($request);
        
        $this->setNativeSessionHandler($session->getHandler());
        
        $this->addCookie($session);
    
        $session->start();
    
        return $session;
    }
    
    /**
     * Close the session handling for the request.
     *
     * @param  \Royalcms\Component\Session\SessionInterface  $session
     * @return void
     */
    protected function closeSession(SessionInterface $session)
    {
        $session->save();
    
        $this->collectGarbage($session);
    }
    
    /**
     * Get the full URL for the request.
     *
     * @param  \Symfony\Component\HttpFoundation\Request  $request
     * @return string
     */
    protected function getUrl(Request $request)
    {
        $url = rtrim(preg_replace('/\?.*/', '', $request->getUri()), '/');
    
        return $request->getQueryString() ? $url.'?'.$request->getQueryString() : $url;
    }
    
    /**
     * Remove the garbage from the session if necessary.
     *
     * @param  \Royalcms\Component\Session\SessionInterface  $session
     * @return void
     */
    protected function collectGarbage(SessionInterface $session)
    {
        $config = $this->manager->getSessionConfig();
    
        // Here we will see if this request hits the garbage collection lottery by hitting
        // the odds needed to perform garbage collection on any given request. If we do
        // hit it, we'll call this handler to let it delete all the expired sessions.
        if ($this->configHitsLottery($config))
        {
            $session->getHandler()->gc($this->getLifetimeSeconds());
        }
    }
    
    /**
     * Determine if the configuration odds hit the lottery.
     *
     * @param  array  $config
     * @return bool
     */
    protected function configHitsLottery(array $config)
    {
        return mt_rand(1, $config['lottery'][1]) <= $config['lottery'][0];
    }
    
    /**
     * Add the session cookie to the application response.
     *
     * @param  \Symfony\Component\HttpFoundation\Session\SessionInterface  $session
     * @return void
     */
    protected function addCookie(SessionInterface $session)
    {
        $s = $session;
    
        if ($this->sessionIsPersistent($c = $this->manager->getSessionConfig()))
        {
            $secure = array_get($c, 'secure', false);
            $httponly = array_get($c, 'httponly', false);
            
            /*
            $response->headers->setCookie(new Cookie(
                $s->getName(), $s->getId(), $this->getCookieLifetime(), $c['path'], $c['domain'], $secure
            ));
            */
            
            /* 设置缓存限制为 "private, must-revalidate", "nocache" */
            session_cache_limiter('nocache');
            session_id($s->getId());
            session_name($s->getName());
            session_set_cookie_params(
                $this->getLifetimeSeconds(),
                $c['path'],
                $c['domain'],
                $secure,
                $httponly
            );
        }
    }
    
    /**
     * Get the session lifetime in seconds.
     *
     *
     */
    protected function getLifetimeSeconds()
    {
        return array_get($this->manager->getSessionConfig(), 'lifetime') * 60;
    }
    
    /**
     * Get the cookie lifetime in seconds.
     *
     * @return int
     */
    protected function getCookieLifetime()
    {
        $config = $this->manager->getSessionConfig();
    
        return $config['expire_on_close'] ? 0 : Carbon::now()->addMinutes($config['lifetime']);
    }
    
    /**
     * Determine if a session driver has been configured.
     *
     * @return bool
     */
    protected function sessionConfigured()
    {
        return ! is_null(array_get($this->manager->getSessionConfig(), 'driver'));
    }
    
    /**
     * Determine if the configured session driver is persistent.
     *
     * @param  array|null  $config
     * @return bool
     */
    protected function sessionIsPersistent(array $config = null)
    {
        // Some session drivers are not persistent, such as the test array driver or even
        // when the developer don't have a session driver configured at all, which the
        // session cookies will not need to get set on any responses in those cases.
        $config = $config ?: $this->manager->getSessionConfig();
    
        return ! in_array($config['driver'], array(null, 'array'));
    }
    
    /**
     * Get the session implementation from the manager.
     *
     * @return \Royalcms\Component\Session\SessionInterface
     */
    public function getSession(Request $request)
    {
        $session = $this->manager->driver(); 
        
        if (RC_Hook::has_filter('royalcms_session_id') && RC_Hook::apply_filters('royalcms_session_id', null))
        {
            $sessionId = RC_Hook::apply_filters('royalcms_session_id', null);
        }
        elseif ($request->exists($session->getName())) 
        {
            $sessionId = $request->input($session->getName());
        }
        elseif ($request->cookies->has($session->getName())) 
        {
            $sessionId = $request->cookies->get($session->getName());
        }
        else 
        {
            $sessionId = null;
        }

        $session->setId($sessionId);

        return $session;
    }
    
}

// end