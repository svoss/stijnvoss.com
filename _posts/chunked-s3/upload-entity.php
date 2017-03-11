<?php
namespace [YOUR_NAMESPACE]\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Class Upload
 * @ORM\Table(indexes={@ORM\Index(name="upload_fn", columns={"filename"} ),@ORM\Index(name="state", columns={"state"} )})
 * @ORM\Entity(repositoryClass="[YOUR_NAMEPSACE]\Repository\UploadRepository")
 */
class Upload
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     * @var int
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=100)
     * @var string
     */
    protected $filename;

    /**
     * @var User
     * @ORM\ManyToOne(targetEntity="User")
     */
    protected $user;

    /**
     *
     * @ORM\Column(type="integer")
     * @var int
     */
    protected $timesSigned;

    /**
     *
     * @ORM\Column(type="datetime")
     * @var \DateTime
     */
    protected $lastSigned;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     * @var \DateTime
     */
    protected $doneTime;


    /**
     * @ORM\Column(type="integer")
     * @var int
     */
    protected $chunks;


    /**
     * @ORM\Column(type="string")
     * @var string
     */
    protected $state;


    /**
     * Upload constructor.
     */
    public function __construct()
    {
        $this->lastSigned = new \DateTime();
        $this->timesSigned = 1;
        $this->merged = false;
        $this->chunks = 0;
        $this->state = '';
    }

    /**
     * @param User      $user
     * @param string    $filename
     * @return Upload
     */
    public static function createNew($user, $filename)
    {
        $x = new self();
        $x->setUser($user);
        $x->setFilename($filename);

        return $x;
    }


    /**
     * @return \DateTime
     */
    public function getDoneTime()
    {
        return $this->doneTime;
    }

    /**
     * @param \DateTime $doneTime
     *
     * @return self
     */
    public function setDoneTime($doneTime)
    {
        $this->doneTime = $doneTime;
        return $this;
    }


    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param int $id
     *
     * @return self
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * @param string $filename
     *
     * @return self
     */
    public function setFilename($filename)
    {
        $this->filename = $filename;
        return $this;
    }

    /**
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param User $user
     *
     * @return self
     */
    public function setUser($user)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return int
     */
    public function getTimesSigned()
    {
        return $this->timesSigned;
    }

    /**
     * @param int $timesSigned
     *
     * @return self
     */
    public function setTimesSigned($timesSigned)
    {
        $this->timesSigned = $timesSigned;
        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getLastSigned()
    {
        return $this->lastSigned;
    }

    /**
     * @param \DateTime $lastSigned
     *
     * @return self
     */
    public function setLastSigned($lastSigned)
    {
        $this->lastSigned = $lastSigned;
        return $this;
    }

    /**
     * @return int
     */
    public function getChunks()
    {
        return $this->chunks;
    }

    /**
     * @param int $chunks
     *
     * @return self
     */
    public function setChunks($chunks)
    {
        $this->chunks = $chunks;
        return $this;
    }

    /**
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @param string $state
     *
     * @return self
     */
    public function setState($state)
    {
        $this->state = $state;
        return $this;
    }
}