<template>
    <el-container>
        <Login :visible.sync="!this.$store.state.is_logged_in" />
        <Profile v-if="this.$store.state.is_logged_in" :show="showProfile" @close="showProfile = false" />
        <el-header>
            <el-row>
                <el-col :span="12">
                    <el-button type="text" class="btn-big text-white" @click.prevent="collapse = !collapse" :icon="collapse ? 'el-icon-s-unfold' : 'el-icon-s-fold'"></el-button>
                    <span class="brand"> {{appName}} </span>
                </el-col>
                <el-col :span="12" class="text-right">
                    <el-dropdown @command="handleCommand">
                        <span class="el-dropdown-link" style="cursor:pointer">Welcome, {{$store.state.user.name}}!</span>
                        <el-dropdown-menu slot="dropdown">
                            <el-dropdown-item command="profile">My Profile</el-dropdown-item>
                            <el-dropdown-item command="logout">Logout</el-dropdown-item>
                        </el-dropdown-menu>
                    </el-dropdown>
                </el-col>
            </el-row>
        </el-header>

        <el-container>
            <el-aside width="auto">
                <el-menu
                :collapse="collapse"
                default-active="1"
                background-color="#060446"
                text-color="#fff"
                class="sidebar"
                active-text-color="#cc0000">
                    <el-menu-item v-for="(m, i) in menus" :index="(++i).toString()" :key="i" @click="$router.push(m.path)">
                        <i :class="m.icon"></i><span slot="title">{{m.label}}</span>
                    </el-menu-item>
                </el-menu>
            </el-aside>
            <el-main style="padding:20px">
                <el-collapse-transition>
                    <router-view @back="goBack"></router-view>
                </el-collapse-transition>
            </el-main>
        </el-container>
    </el-container>
</template>

<script>
import Login from './pages/Login'
import Profile from './pages/Profile'

export default {
    components: { Login, Profile },
    computed: {
        menus() {
            return [
                {label: 'Home', icon: 'el-icon-s-home', path: '/' },
                {label: 'Transactions', icon: 'el-icon-document-copy', path: 'parking-transaction' },
                // {label: 'Member Renewal', icon: 'el-icon-refresh', path: 'member-renewal' },
                {label: 'Report', icon: 'el-icon-data-analysis', path: 'report' },
                {label: 'Gates', icon: 'el-icon-minus', path: 'parking-gate' },
                {label: 'Vehicle Type', icon: 'el-icon-truck', path: 'vehicle-type' },
                {label: 'Location Identity', icon: 'el-icon-office-building', path: 'location-identity' },
                {label: 'Members', icon: 'el-icon-bank-card', path: 'parking-member' },
                {label: 'Users', icon: 'el-icon-user', path: 'user' },
                // {label: 'Log', icon: 'el-icon-bell', path: 'log' },
            ]
        }
    },
    data() {
        return {
            collapse: true,
            appName: APP_NAME,
            showProfile: false,
            loginForm: !this.$store.state.is_logged_in,
            notif: false
        }
    },
    methods: {
        goBack() {
            window.history.back();
        },
        handleCommand(command) {
            if (command === 'logout') {
                axios.get('/logout').then(r => {
                    window.localStorage.removeItem('user')
                    window.localStorage.removeItem('token')
                    this.$store.state.user = {}
                    this.$store.state.token = ''
                    this.$store.state.is_logged_in = false
                })
            }

            if(command === 'profile') {
                this.showProfile = true
            }
        },
        getNotification() {
            let params = { read: 0, pageSize: 1 }
            axios.get('/notification', { params: params }).then(r => {
                if (r.data.data.length == 0) {
                    return
                }

                // jika tidak ada notifikasi yg tampil
                if (!this.notif)
                {
                    let n = r.data.data[0]
                    this.notif = true
                    this.$alert(n.message, 'Notifikasi', {
                        type: 'warning',
                        center: true,
                        roundButton: true,
                        confirmButtonText: 'SAYA TELAH MEMBACA NOTIFIKASI INI',
                        confirmButtonClass: 'bg-red',
                        beforeClose: (action, instance, done) => {
                            this.notif = false
                            done()
                        }
                    }).then(() => {
                        this.notif = false
                        axios.put('/notification/' + n.id, { read: 1 }).then(rr => {
                            console.log(rr.data)
                        }).catch(e => console.log(e))
                    })
                }
            }).catch(e => console.log(e))
        }
    },
    mounted() {
        setInterval(this.getNotification, 3000)
    }
}
</script>

<style lang="css" scoped>
.brand {
    font-size: 22px;
    margin-left: 20px;
}

.btn-big {
    font-size: 22px;
}

.el-header {
    /* background-color: #006098; */
    background-color: #254ec1;
    color: #fff;
    line-height: 60px;
}

.sidebar {
    background-color: #060446;
    border-color: #060446;
    height: calc(100vh - 60px);
}

.sidebar:not(.el-menu--collapse) {
    width: 180px;
}

.el-aside {
    height: calc(100vh - 60px);
}

.el-main {
    background-color: #FFF;
}

.el-dropdown-link {
    color: #fff;
}
</style>