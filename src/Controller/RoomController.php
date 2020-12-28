<?php

namespace App\Controller;

use App\Entity\Rooms;
use App\Entity\User;
use App\Form\Type\NewMemberType;
use App\Form\Type\RoomType;
use App\Service\AddUserService;
use App\Service\InviteService;
use App\Service\NotificationService;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RoomController extends AbstractController
{

    /**
     * @Route("/room/new", name="room_new")
     */
    public function newRoom(Request $request, ValidatorInterface $validator)
    {
        if ($request->get('id')) {
            $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(array('id'=>$request->get('id')));
        }else {
            $room = new Rooms();
            $room->addUser($this->getUser());
            $now = new \DateTime();
            $room->setStart($now);
            $end = clone $now;
            $end->modify('+1 hour');
            $room->setEnddate($end);
            $room->setUid(rand(01,99).time());
            $room->setModerator($this->getUser());
        }

        $form = $this->createForm(RoomType::class, $room, ['server' => $this->getUser()->getServers(), 'action' => $this->generateUrl('room_new',['id'=>$room->getId()])]);
        $form->handleRequest($request);

        $errors = array();
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $errors = $validator->validate($data);
            if (count($errors) == 0) {
                $em = $this->getDoctrine()->getManager();
                $em->persist($room);
                $em->flush();
                return $this->redirectToRoute('dashboard');
            }
        }
        $title = 'Neue Konferenz erstellen';

        return $this->render('base/__modalView.html.twig', array('form' => $form->createView(), 'title' => $title));
    }

    /**
     * @Route("/room/add-user", name="room_add_user")
     */
    public function roomAddUser(Request $request, InviteService $inviteService, AddUserService $addUserService)
    {
        $newMember = array();
        $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['id' => $request->get('room')]);
        $form = $this->createForm(NewMemberType::class, $newMember, ['action' => $this->generateUrl('room_add_user', ['room' => $room->getId()])]);
        $form->handleRequest($request);

        $errors = array();
        if ($form->isSubmitted() && $form->isValid()) {


            $newMembers = $form->getData();
            $lines = explode("\n", $newMembers['member']);

            if (!empty($lines)) {
                $em = $this->getDoctrine()->getManager();
                foreach ($lines as $line) {
                    $newMember = trim($line);
                    $user = $inviteService->newUser($newMember);
                    $user->addRoom($room);
                    $em->persist($user);
                    $addUserService->addUser($user, $room);

                }
                $em->flush();
                return $this->redirectToRoute('dashboard');
            }
        }
        $title = 'Teilnehmer hinzufügen';

        return $this->render('base/__modalView.html.twig', array('form' => $form->createView(), 'title' => $title));
    }


    /**
     * @Route("/room/join", name="room_join")
     */
    public
    function joinRoom(Request $request)
    {

        $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['id' => $request->get('room')]);
        if ($request->get('t') === 'a') {
            $type = 'jitsi-meet://';
        }else {
            $type = 'https://';
        }
        if (in_array($this->getUser(), $room->getUser()->toarray())) {
            $jitsi_server_url = $type . $room->getServer()->getUrl();
            $jitsi_jwt_token_secret = $room->getServer()->getAppSecret();

            $payload = array(
                "aud" => $room->getServer()->getAppId(),
                "iss" => "jitsi_manager",
                "sub" => $room->getServer()->getUrl(),
                "room" => $room->getId(),
                "context" => [
                    'user' => [
                        'name' => $this->getUser()->getUsername()
                    ]
                ]
            );

            $token = JWT::encode($payload, $jitsi_jwt_token_secret);
            $url = $jitsi_server_url . '/' . $room->getUid() . '?jwt=' . $token;
            return $this->redirect($url);
        }

    }

    /**
     * @Route("/join", name="room_join_guests")
     */
    public
    function joinRoomGuests(Request $request)
    {

        $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['id' => $request->get('room')]);
        $jitsi_server_url = 'jitsi-meet://' . $room->getServer()->getUrl();
        $jitsi_jwt_token_secret = $room->getServer()->getAppSecret();

        $payload = array(
            "aud" => $room->getServer()->getAppId(),
            "iss" => "jitsi_manager",
            "sub" => $room->getServer()->getUrl(),
            "room" => $room->getId(),
            "context" => [
                'user' => [
                    'name' => $this->getUser()->getUsername()
                ]
            ]
        );

        $token = JWT::encode($payload, $jitsi_jwt_token_secret);
        $url = $jitsi_server_url . '/' . $room->getUid() . '?jwt=' . $token;
        return $this->redirect($url);
    }

    /**
     * @Route("/room/show-user", name="room_show_user")
     */
    public
    function roomShowUser(Request $request)
    {

        $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['id' => $request->get('room')]);
        if (in_array($this->getUser(), $room->getUser()->toarray())) {
            $title = 'Teilnehmer bearbeiten';

            return $this->render('room/showUser.html.twig', array('room' => $room, 'title' => $title));
        }
    }

    /**
     * @Route("/room/user/remove", name="room_user_remove")
     */
    public
    function roomUserRemove(Request $request)
    {

        $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['id' => $request->get('room')]);
        $user = $this->getDoctrine()->getRepository(User::class)->findOneBy(['id' => $request->get('user')]);
        if ($this->getUser() === $room->getModerator()) {
            $room->removeUser($user);
            $em = $this->getDoctrine()->getManager();
            $em->persist($room);
            $em->flush();

            return $this->redirectToRoute('dashboard');
        }
    }

    /**
     * @Route("/room/remove", name="room_remove")
     */
    public
    function roomRemove(Request $request)
    {

        $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['id' => $request->get('room')]);
        if ($this->getUser() === $room->getModerator()) {
            $em = $this->getDoctrine()->getManager();
            $em->remove($room);
            $em->flush();

            return $this->redirectToRoute('dashboard');
        }
    }

    /**
     * @Route("/room/copy", name="room_copy")
     */
    public
    function roomCopy(Request $request)
    {

        $room = $this->getDoctrine()->getRepository(Rooms::class)->findOneBy(['id' => $request->get('room')]);
        if ($this->getUser() === $room->getModerator()) {
            $em = $this->getDoctrine()->getManager();
            $em->remove($room);
            $em->flush();

            return $this->redirectToRoute('dashboard');
        }
    }

}
