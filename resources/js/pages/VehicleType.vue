<template>
    <div>
        <el-form inline class="text-right" @submit.native.prevent="() => { return }">
            <el-form-item>
                <el-button size="small" icon="el-icon-plus" @click="openForm({})" type="primary">TAMBAH JENIS KENDARAAN</el-button>
            </el-form-item>
            <el-form-item>
                <el-input size="small" v-model="keyword" placeholder="Search" prefix-icon="el-icon-search" :clearable="true" @change="(v) => { keyword = v; requestData(); }">
                </el-input>
            </el-form-item>
            <el-form-item>
                <el-pagination background
                @current-change="(p) => { page = p; requestData(); }"
                @size-change="(s) => { pageSize = s; requestData(); }"
                layout="total, sizes, prev, next"
                :page-size="pageSize"
                :page-sizes="[10, 25, 50, 100]"
                :total="tableData.total">
                </el-pagination>
            </el-form-item>
        </el-form>

        <el-table :data="tableData.data" stripe
        :default-sort = "{prop: sort, order: order}"
        height="calc(100vh - 260px)"
        v-loading="loading"
        @sort-change="sortChange">
            <el-table-column prop="name" label="Nama" sortable="custom" min-width="100px"></el-table-column>
            <el-table-column prop="shortcut_key" label="Shortcut" sortable="custom" align="center" header-align="center" min-width="100px"></el-table-column>
            <el-table-column label="Mode Tarif" sortable="custom" align="center" header-align="center" min-width="120px">
                <template slot-scope="scope">
                    {{scope.row.mode_tarif ? 'PROGRESIF' : 'FLAT'}}
                </template>
            </el-table-column>
            <el-table-column label="Mode Inap" sortable="custom" align="center" header-align="center" min-width="120px">
                <template slot-scope="scope">
                    {{scope.row.mode_menginap ? 'LEWAT TENGAH MALAM' : '24 JAM DARI CHECK IN'}}
                </template>
            </el-table-column>
            <el-table-column prop="tarif_flat" label="Tarif Flat" sortable="custom" align="center" header-align="center" min-width="120px">
                <template slot-scope="scope">
                    Rp {{scope.row.tarif_flat | formatNumber}}
                </template>
            </el-table-column>
            <el-table-column label="Tarif Non Flat" min-width="250px">
                <template slot-scope="scope">
                    Tarif {{scope.row.menit_pertama}} menit pertama = Rp {{scope.row.tarif_menit_pertama | formatNumber}} <br />
                    Tarif {{scope.row.menit_selanjutnya}} menit selanjutnya = Rp {{scope.row.tarif_menit_selanjutnya | formatNumber}} <br />
                    Tarif maksimal per hari = Rp {{scope.row.tarif_maksimum | formatNumber}} <br />
                    Tarif menginap per hari = Rp {{scope.row.tarif_menginap | formatNumber}} <br />
                </template>
            </el-table-column>
            <el-table-column prop="denda_tiket_hilang" label="Denda Tiket Hilang" sortable="custom" align="center" header-align="center" min-width="150px">
                <template slot-scope="scope">
                    Rp {{scope.row.denda_tiket_hilang | formatNumber}}
                </template>
            </el-table-column>
            <!-- <el-table-column prop="is_default" label="Default" sortable="custom" align="center" header-align="center">
                <template slot-scope="scope">
                    <el-tag size="mini" :type="scope.row.is_default ? 'success' : 'info'">{{scope.row.is_default ? 'Ya' : 'Tidak'}}</el-tag>
                </template>
            </el-table-column> -->
            <el-table-column width="40px" align="center" header-align="center">
                <template slot="header">
                    <el-button
                    type="text"
                    class="text-white"
                    @click="() => { page = 1; keyword = ''; requestData(); }"
                    icon="el-icon-refresh">
                    </el-button>
                </template>
                <template slot-scope="scope">
                    <el-dropdown>
                        <span class="el-dropdown-link">
                            <i class="el-icon-more"></i>
                        </span>
                        <el-dropdown-menu slot="dropdown">
                            <el-dropdown-item @click.native.prevent="openForm(scope.row)"><i class="el-icon-edit-outline"></i> Edit</el-dropdown-item>
                            <el-dropdown-item @click.native.prevent="deleteData(scope.row.id)"><i class="el-icon-delete"></i> Hapus</el-dropdown-item>
                        </el-dropdown-menu>
                    </el-dropdown>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog top="60px" :visible.sync="showForm" :title="!!formModel.id ? 'EDIT JENIS KENDARAAN' : 'TAMBAH JENIS KENDARAAN'" width="600px" v-loading="loading" :close-on-click-modal="false">
            <el-alert type="error" title="ERROR"
                :description="error.message + '\n' + error.file + ':' + error.line"
                v-show="error.message"
                style="margin-bottom:15px;">
            </el-alert>

            <el-form label-width="180px" label-position="left">
                <el-form-item label="Nama" :class="formErrors.name ? 'is-error' : ''">
                    <el-input placeholder="Nama" v-model="formModel.name"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.name">{{formErrors.name[0]}}</div>
                </el-form-item>

                <el-form-item label="Shortcut Key" :class="formErrors.shortcut_key ? 'is-error' : ''">
                    <el-input maxlength="1" placeholder="Shortcut Key" v-model="formModel.shortcut_key"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.shortcut_key">{{formErrors.shortcut_key[0]}}</div>
                </el-form-item>

                <el-form-item label="Mode Tarif" :class="formErrors.mode_tarif ? 'is-error' : ''">
                    <el-select placeholder="FLAT/PROGRESIF" v-model="formModel.mode_tarif" style="width:100%">
                        <el-option :value="0" label="FLAT"></el-option>
                        <el-option :value="1" label="PROGRESIF"></el-option>
                    </el-select>
                    <div class="el-form-item__error" v-if="formErrors.mode_tarif">{{formErrors.mode_tarif[0]}}</div>
                </el-form-item>

                <el-form-item label="Mode Inap" :class="formErrors.mode_menginap ? 'is-error' : ''">
                    <el-select placeholder="Mode Inap" v-model="formModel.mode_menginap" style="width:100%">
                        <el-option :value="0" label="24 JAM DARI CHECK IN"></el-option>
                        <el-option :value="1" label="LEWAT TENGAH MALAM"></el-option>
                    </el-select>
                    <div class="el-form-item__error" v-if="formErrors.mode_menginap">{{formErrors.mode_menginap[0]}}</div>
                </el-form-item>

                <el-form-item label="Tarif Flat (Rp)" :class="formErrors.tarif_flat ? 'is-error' : ''">
                    <el-input type="number" :step="500" placeholder="Tarif Flat (Rp)" v-model="formModel.tarif_flat"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.tarif_flat">{{formErrors.tarif_flat[0]}}</div>
                </el-form-item>

                <el-form-item label="Tarif Progresif">
                    <div>
                        Tarif
                        <el-input size="small" style="width:80px;margin: 0 5px;" type="number" v-model="formModel.menit_pertama"></el-input>
                        menit pertama = Rp.
                        <el-input size="small" style="width:111px;margin-left:23px;" type="number" v-model="formModel.tarif_menit_pertama"></el-input>
                    </div>
                    <div>
                        Tarif
                        <el-input size="small" style="width:80px;margin: 0 5px;" type="number" v-model="formModel.menit_selanjutnya"></el-input>
                        menit selanjutnya = Rp.
                        <el-input size="small" style="width:111px;margin-left:6px;" type="number" v-model="formModel.tarif_menit_selanjutnya"></el-input>
                    </div>
                    <div>
                        Tarif maksimal per hari = Rp
                        <el-input size="small" style="width:205px;margin-left: 10px;" type="number" v-model="formModel.tarif_maksimum"></el-input>
                    </div>
                    <div>
                        Tarif menginap per hari = Rp
                        <el-input size="small" style="width:205px;margin-left: 5px;" type="number" v-model="formModel.tarif_menginap"></el-input>
                    </div>
                </el-form-item>

                <el-form-item label="Denda Tiket Hilang (Rp)" :class="formErrors.denda_tiket_hilang ? 'is-error' : ''">
                    <el-input type="number" :step="500" placeholder="Denda Tiket Hilang (Rp)" v-model="formModel.denda_tiket_hilang"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.denda_tiket_hilang">{{formErrors.denda_tiket_hilang[0]}}</div>
                </el-form-item>

                <!-- <el-form-item label="Menit Pertama (menit)" :class="formErrors.menit_pertama ? 'is-error' : ''">
                    <el-input type="number" placeholder="Menit Pertama (menit)" v-model="formModel.menit_pertama"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.menit_pertama">{{formErrors.menit_pertama[0]}}</div>
                </el-form-item>

                <el-form-item label="Tarif Menit Pertama (Rp)" :class="formErrors.tarif_menit_pertama ? 'is-error' : ''">
                    <el-input type="number" placeholder="Tarif Menit Pertama (Rp)" v-model="formModel.tarif_menit_pertama"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.tarif_menit_pertama">{{formErrors.tarif_menit_pertama[0]}}</div>
                </el-form-item>

                <el-form-item label="Menit Berikutnya (menit)" :class="formErrors.menit_selanjutnya ? 'is-error' : ''">
                    <el-input type="number" placeholder="Menit Berikutnya (menit)" v-model="formModel.menit_selanjutnya"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.menit_selanjutnya">{{formErrors.menit_selanjutnya[0]}}</div>
                </el-form-item>

                <el-form-item label="Tarif Menit Berikutnya (Rp)" :class="formErrors.tarif_menit_selanjutnya ? 'is-error' : ''">
                    <el-input type="number" placeholder="Tarif Menit Berikutnya (Rp)" v-model="formModel.tarif_menit_selanjutnya"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.tarif_menit_selanjutnya">{{formErrors.tarif_menit_selanjutnya[0]}}</div>
                </el-form-item>

                <el-form-item label="Tarif Maksimal Per Hari (Rp)" :class="formErrors.tarif_maksimum ? 'is-error' : ''">
                    <el-input type="number" placeholder="Tarif Maksimal Per Hari (Rp)" v-model="formModel.tarif_maksimum"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.tarif_maksimum">{{formErrors.tarif_maksimum[0]}}</div>
                </el-form-item>

                <el-form-item label="Tarif Menginap Per Hari (Rp)" :class="formErrors.tarif_menginap ? 'is-error' : ''">
                    <el-input type="number" placeholder="Tarif Menginap Per Hari" v-model="formModel.tarif_menginap"></el-input>
                    <div class="el-form-item__error" v-if="formErrors.tarif_menginap">{{formErrors.tarif_menginap[0]}}</div>
                </el-form-item> -->

                <!-- <el-form-item label="Default" :class="formErrors.is_default ? 'is-error' : ''">
                    <el-switch
                    :active-value="1"
                    :inactive-value="0"
                    v-model="formModel.is_default"
                    active-color="#13ce66">
                    </el-switch>
                    <el-tag :type="formModel.is_default ? 'success' : 'info'" size="small" style="margin-left:10px">{{!!formModel.is_default ? 'Ya' : 'Tidak'}}</el-tag>
                    <div class="el-form-item__error" v-if="formErrors.is_default">{{formErrors.is_default[0]}}</div>
                </el-form-item> -->
            </el-form>
            <span slot="footer" class="dialog-footer">
                <el-button icon="el-icon-success" type="primary" @click="() => !!formModel.id ? update() : store()">SIMPAN</el-button>
                <el-button icon="el-icon-error" type="info" @click="showForm = false">BATAL</el-button>
            </span>
        </el-dialog>
    </div>
</template>

<script>
export default {
    data() {
        return {
            showForm: false,
            formErrors: {},
            error: {},
            formModel: {},
            keyword: '',
            page: 1,
            pageSize: 10,
            tableData: {},
            sort: 'name',
            order: 'ascending',
            loading: false
        }
    },
    methods: {
        sortChange(c) {
            if (c.prop != this.sort || c.order != this.order) {
                this.sort = c.prop; this.order = c.order; this.requestData()
            }
        },
        openForm(data) {
            this.error = {}
            this.formErrors = {}
            this.formModel = JSON.parse(JSON.stringify(data));
            this.showForm = true
        },
        store() {
            this.loading = true;
            axios.post('/vehicleType', this.formModel).then(r => {
                this.showForm = false;
                this.$message({
                    message: 'Data berhasil disimpan.',
                    type: 'success',
                    showClose: true
                });
                this.requestData();
            }).catch(e => {
                if (e.response.status == 422) {
                    this.error = {}
                    this.formErrors = e.response.data.errors;
                }

                if (e.response.status == 500) {
                    this.formErrors = {}
                    this.error = e.response.data;
                }
            }).finally(() => {
                this.loading = false
            })
        },
        update() {
            this.loading = true;
            axios.put('/vehicleType/' + this.formModel.id, this.formModel).then(r => {
                this.showForm = false
                this.$message({
                    message: 'Data berhasil disimpan.',
                    type: 'success',
                    showClose: true
                });
                this.requestData()
            }).catch(e => {
                if (e.response.status == 422) {
                    this.error = {}
                    this.formErrors = e.response.data.errors;
                }

                if (e.response.status == 500) {
                    this.formErrors = {}
                    this.error = e.response.data;
                }
            }).finally(() => {
                this.loading = false
            })
        },
        deleteData(id) {
            this.$confirm('Anda yakin akan menghapus data ini?', 'Warning', { type: 'warning' }).then(() => {
                axios.delete('/vehicleType/' + id).then(r => {
                    this.requestData();
                    this.$message({
                        message: r.data.message,
                        type: 'success',
                        showClose: true
                    });
                }).catch(e => {
                    this.$message({
                        message: e.response.data.message,
                        type: 'error',
                        showClose: true
                    });
                })
            }).catch(() => console.log(e));
        },
        requestData() {
            let params = {
                page: this.page,
                keyword: this.keyword,
                pageSize: this.pageSize,
                sort: this.sort,
                order: this.order,
            }

            this.loading = true;
            axios.get('/vehicleType', {params: params}).then(r => {
                    this.loading = false;
                    this.tableData = r.data
            }).catch(e => {
                this.loading = false;
                if (e.response.status == 500) {
                    this.$message({
                        message: e.response.data.message + '\n' + e.response.data.file + ':' + e.response.data.line,
                        type: 'error',
                        showClose: true
                    });
                }
            })
        }
    },
    mounted() {
        this.requestData();
    }
}
</script>
