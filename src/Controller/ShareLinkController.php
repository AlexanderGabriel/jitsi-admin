<?php

namespace App\Controller;

use App\Entity\Rooms;
use App\Entity\Subscriber;
use App\Entity\User;
use App\Form\Type\PublicRegisterType;
use App\Service\PexelService;
use App\Service\RoomService;
use App\Service\SubcriptionService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use function Symfony\Component\String\s;

class ShareLinkController extends AbstractController
{
    private $em;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->em = $entityManager;
    }

    /**
     * @Route("/room/share/link/{id}", name="share_link")
     * @ParamConverter("rooms")
     */
    public function index(Rooms $rooms): Response
    {
        if (!$rooms || !$rooms->getModerator() == $this->getUser() || $rooms->getPublic() != true) {
            throw new NotFoundHttpException('Not found');
        }
        return $this->render('share_link/__shareLinkModal.html.twig', array('room' => $rooms));

    }

    /**
     * @Route("/subscribe/self/{uid}", name="public_subscribe_participant")
     */
    public function participants($uid, Request $request, SubcriptionService $subcriptionService, TranslatorInterface $translator, PexelService $pexelService): Response
    {
        $rooms = new Rooms();
        $moderator = false;
        $rooms = $this->em->getRepository(Rooms::class)->findOneBy(array('uidParticipant' => $uid,'public'=>true));
        if (!$rooms) {
            $rooms = $this->em->getRepository(Rooms::class)->findOneBy(array('uidModerator' => $uid,'public'=>true));
            if ($rooms) {
                $moderator = true;
            }
        }
        if (!$rooms || $rooms->getModerator() === null) {
            return $this->redirectToRoute('join_index_no_slug', ['snack' => $translator->trans('Fehler, Bitte kontrollieren Sie ihre Daten.'), 'color'=>'danger']);
        }

        $data = array('email' => '');
        $form = $this->createForm(PublicRegisterType::class, $data);
        $form->handleRequest($request);
        $errors = array();
        $snack = $translator->trans('Bitte geben Sie ihre Daten ein');
        $color = 'success';
        $server = null;
        if($rooms->getMaxParticipants() && (sizeof($rooms->getUser()->toArray()) >= $rooms->getMaxParticipants())){
            $snack = $translator->trans('Die maximale Teilnehmeranzahl ist bereits erreicht.');
            $color ='danger';
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $res = $subcriptionService->subscripe($data['email'], $rooms, $moderator);
            $snack = $res['text'];
            $color = $res['color'];
        }
        $server = $rooms->getServer();
        $image = $pexelService->getImageFromPexels();
        return $this->render('share_link/subscribe.html.twig', [
            'form' => $form->createView(),
            'snack' => $snack,
            'server' => $server,
            'image' => $image,
            'room' => $rooms,
            'color' => $color,
        ]);
    }


    /**
     * @Route("/subscribe/optIn/{uid}", name="public_subscribe_doupleOptIn")
     */
    public function doupleoptin($uid, SubcriptionService $subcriptionService, TranslatorInterface $translator, UserService $userService, PexelService $pexelService): Response
    {
        $subscriber = $this->em->getRepository(Subscriber::class)->findOneBy(array('uid' => $uid));
        $res = $subcriptionService->acceptSub($subscriber);
        $server = null;
        if($subscriber){
            $subscriber->getRoom()->getServer();
        }
        $message = $res['message'];
        $title = $res['title'];
        $image = $pexelService->getImageFromPexels();
        return $this->render('share_link/subscribeSuccess.html.twig', array('server'=>$server,'message' => $message, 'title' => $title, 'image' => $image));
    }
}
