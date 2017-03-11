<?php
namespace [YOUR_NAMESPACE]\Repository;


use Doctrine\ORM\EntityRepository;
use [YOUR_NAMESPACE]\Entity\Upload;
use [YOUR_NAMESPACE]\Entity\User;
class UploadRepository extends EntityRepository
{
    /**
     * @param Upload $upload
     */
    public function save($upload)
    {
        $em = $this->getEntityManager();
        $em->persist($upload);
        $em->flush();
    }

    /**
     * @param string $fn
     * @param User   $user
     * @return Upload|null
     */
    public function findByFilename($fn, $user)
    {
        $em = $this->getEntityManager();
        $q = $em->createQuery("SELECT u FROM SMKvvbBundle:Upload u WHERE u.filename = :fn AND u.user = :user");
        $q->setParameter('fn', $fn);
        $q->setParameter('user', $user);

        return $q->getOneOrNullResult();
    }

    /**
     * @param Upload $upload
     */
    public function signNext($upload)
    {
        $em = $this->getEntityManager();
        $q = $em->createQuery("UPDATE SMKvvbBundle:Upload u SET u.lastSigned = CURRENT_TIMESTAMP(), u.timesSigned = u.timesSigned + 1 WHERE u.id=:id");
        $q->setParameter('id', $upload->getId());
        $q->execute();
    }

    /**
     * @param User $user
     *
     * @return int
     */
    public function signsLast24h($user)
    {
        $d = new \DateTime();
        $d->modify("-24 hours");
        $em = $this->getEntityManager();
        $q = $em->createQuery("SELECT sum(u.timesSigned) FROM SMKvvbBundle:Upload u WHERE u.user =:user AND u.lastSigned > :date");
        $q->setParameter("user", $user);
        $q->setParameter('date', $d);
        
        return $q->getSingleScalarResult();
    }


